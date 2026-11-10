<?php

namespace App\Services;

use App\Models\Family;
use App\Models\User;
use App\Repositories\AllowanceRepository;
use App\Repositories\FamilyRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AllowanceLedger
{
    public function __construct(private readonly AllowanceRepository $accounts, private readonly FamilyRepository $families) {}

    public function setRate(User $guardian, int $cents): void
    {
        DB::transaction(function () use ($guardian, $cents): void {
            $family = $this->families->lock($guardian->family_id);
            $latest = $this->accounts->currentRate($family->id);
            if ($latest && (int) $latest->cents_per_point === $cents) {
                return;
            }

            $id = $this->accounts->insertRate([
                'family_id' => $family->id,
                'guardian_id' => $guardian->id,
                'cents_per_point' => $cents,
                'effective_at' => $latest ? $this->now($family)->utc()->addSecond()->startOfSecond() : $family->created_at,
            ]);

            if ($latest === null) {
                $this->accounts->valueExistingCredits($family->id, $id, $cents);
            }
        });
    }

    public function credit(object $occurrence, CarbonImmutable $now): void
    {
        $rate = $this->accounts->currentRate($occurrence->family_id, $occurrence->opens_at);

        $this->accounts->insertCredit([
            'occurrence_id' => $occurrence->id,
            'points' => $occurrence->points,
            'approved_at' => $now,
            'conversion_rate_id' => $rate?->id,
            'amount_cents' => $rate ? $occurrence->points * $rate->cents_per_point : null,
        ]);
    }

    public function ensureCreditCanBeChanged(string $occurrence): void
    {
        if ($this->accounts->creditIsSettled($occurrence)) {
            throw ValidationException::withMessages(['mission' => 'Os pontos desta entrega já entraram em uma mesada fechada.']);
        }
    }

    public function revokeCredit(string $occurrence): void
    {
        $this->accounts->removeCredit($occurrence);
    }

    public function settle(User $child): void
    {
        DB::transaction(function () use ($child): void {
            $family = $this->families->lock($child->family_id);
            $this->closePastMonths($child, $this->now($family));
        });
    }

    public function manual(User $child, User $guardian, string $requestId, string $preview): ?string
    {
        return DB::transaction(function () use ($child, $guardian, $requestId, $preview): ?string {
            $family = $this->families->lock($child->family_id);
            $previous = $this->accounts->manualPayoutId($child, $requestId);
            if ($previous !== null) {
                return $previous;
            }

            $now = $this->now($family);
            $this->closePastMonths($child, $now);
            $credits = $this->accounts->availableCredits($child);
            if ($credits->isEmpty() || $credits->contains(fn ($credit) => $credit->amount_cents === null)
                || ! hash_equals($this->fingerprint($credits), $preview)) {
                return null;
            }

            return $this->createPayout($child, $credits, $now, null, $guardian->id, $requestId);
        });
    }

    public function markPaid(User $child, User $guardian, string $payout): void
    {
        DB::transaction(function () use ($child, $guardian, $payout): void {
            $family = $this->families->lock($child->family_id);
            $account = $this->accounts->payout($child, $payout);

            if ($account->paid_at === null) {
                $this->accounts->markPaid($child, $payout, $guardian, $this->now($family)->utc());
            }
        });
    }

    public function now(Family $family): CarbonImmutable
    {
        $databaseTime = $this->accounts->databaseTime();

        return $databaseTime === null ? CarbonImmutable::now($family->timezone)
            : CarbonImmutable::parse($databaseTime)->setTimezone($family->timezone);
    }

    public function fingerprint(Collection $credits): string
    {
        return hash('sha256', $credits->sortBy('id')->map(fn ($credit) => "{$credit->id}:{$credit->amount_cents}")->implode(','));
    }

    private function closePastMonths(User $child, CarbonImmutable $now): void
    {
        if (! $this->accounts->hasRate($child->family_id)) {
            return;
        }

        $last = $this->accounts->lastSettledMonth($child);
        $month = $last
            ? CarbonImmutable::parse($last, $now->timezone)->addMonth()
            : $child->created_at->toImmutable()->setTimezone($now->timezone)->startOfMonth();

        while ($month->lt($now->startOfMonth())) {
            $cutoff = $month->addMonth();
            $credits = $this->accounts->availableCredits($child, $cutoff->utc());
            if ($credits->contains(fn ($credit) => $credit->amount_cents === null)) {
                return;
            }

            $competence = $month->toDateString();
            $payout = $credits->isEmpty() ? null : $this->createPayout($child, $credits, $now, $competence);
            $this->accounts->recordSettlement([
                'child_id' => $child->id,
                'competence' => $competence,
                'cutoff_at' => $cutoff->utc(),
                'payout_id' => $payout,
            ]);
            $month = $cutoff;
        }
    }

    private function createPayout(User $child, Collection $credits, CarbonImmutable $now, ?string $competence, ?string $guardian = null, ?string $request = null): string
    {
        $id = (string) Str::uuid();
        $this->accounts->insertPayout([
            'id' => $id, 'child_id' => $child->id, 'created_at' => $now->utc(), 'competence' => $competence,
            'created_by' => $guardian, 'request_id' => $request,
            'points' => $credits->sum('points'), 'amount_cents' => $credits->sum('amount_cents'),
        ]);

        foreach ($credits->chunk(500) as $batch) {
            $this->accounts->insertPayoutItems($batch->map(fn ($credit) => ['credit_id' => $credit->id, 'payout_id' => $id])->all());
        }

        return $id;
    }
}
