<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\FamilyRepository;
use App\Repositories\InboxRepository;
use App\Repositories\MissionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

use function Illuminate\Support\defer;

final class MissionService
{
    public function __construct(
        private readonly MissionRepository $missions,
        private readonly FamilyRepository $families,
        private readonly EvidencePhoto $photos,
        private readonly MissionNotices $notices,
        private readonly AllowanceLedger $ledger,
        private readonly InboxRepository $inbox,
        private readonly MissionPush $push,
    ) {}

    public function requireSubmissionAccess(User $child, string $mission): void
    {
        abort_unless($child->role === User::CHILD, 403);
        $this->missions->find($child, $mission);
    }

    public function submit(User $child, string $mission, array $data): void
    {
        abort_unless($child->role === User::CHILD, 403);
        $photoPath = null;
        try {
            $submissionId = DB::transaction(function () use ($child, $mission, $data, &$photoPath): string {
                $occurrence = $this->missions->find($child, $mission, lock: true);
                $duplicate = $this->missions->requestSubmissionId($mission, $data['request_id']);
                if ($duplicate !== null) {
                    return $duplicate;
                }

                $this->ensureCanSubmit($occurrence);
                $photo = $data['photo'] ?? null;
                if ($occurrence->photo_mode === 'required' && $photo === null) {
                    throw ValidationException::withMessages(['photo' => 'Adicione uma foto para enviar a missão.']);
                }
                if ($occurrence->photo_mode === 'disabled' && $photo !== null) {
                    throw ValidationException::withMessages(['photo' => 'Esta missão recebe apenas comentário ou confirmação.']);
                }

                $id = (string) Str::uuid7();
                if ($photo !== null) {
                    $photoPath = $this->photos->store($photo, $id);
                    $this->ensureCanSubmit($occurrence);
                }

                $this->missions->insertSubmission([
                    'id' => $id,
                    'occurrence_id' => $mission,
                    'request_id' => $data['request_id'],
                    'comment' => $data['comment'] ?? null,
                    'photo_path' => $photoPath,
                    'submitted_at' => now(),
                ]);
                $this->missions->updateOccurrence($mission, ['status' => 'in_review']);
                $this->notices->queue($occurrence, $id, 'submitted');
                $this->push->queueReview($occurrence, $id);

                return $id;
            });
        } catch (Throwable $error) {
            if ($photoPath !== null) {
                $this->photos->remove($photoPath);
            }

            throw $error;
        }

        defer(fn () => $this->notices->deliver($submissionId, 'submitted'))->always();
        defer(fn () => $this->push->deliver($submissionId))->always();
    }

    public function review(User $guardian, string $mission, array $data): void
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        DB::transaction(function () use ($guardian, $mission, $data): void {
            $family = $this->families->lock($guardian->family_id);
            $occurrence = $this->missions->find($guardian, $mission, lock: true);
            $attempt = $this->missions->submission($mission, $data['submission_id']);
            $previous = $attempt->decision;
            $feedback = $data['feedback'] ?? null;
            if ($this->missions->latestSubmissionId($mission) !== $attempt->id) {
                throw ValidationException::withMessages(['mission' => 'Abra a entrega mais recente antes de revisar.']);
            }
            if ($previous === $data['decision'] && $attempt->feedback === $feedback && $attempt->reviewer_id === $guardian->id) {
                return;
            }
            if ($occurrence->status !== ($previous ?? 'in_review')
                || ($previous !== null && (int) ($data['review_version'] ?? -1) !== (int) $attempt->review_version)) {
                throw ValidationException::withMessages(['mission' => 'A revisão mudou. Abra a entrega novamente e confira o resultado atual.']);
            }

            $now = $this->ledger->now($family)->utc();
            $version = $attempt->review_version + 1;
            if ($previous === 'approved') {
                $this->ledger->ensureCreditCanBeChanged($mission);
                if ($data['decision'] !== 'approved') {
                    $this->ledger->revokeCredit($mission);
                }
            }
            if ($previous !== null) {
                $this->missions->archiveReview($attempt);
                $this->inbox->markReviewUnread($occurrence->child_id, $attempt->id);
                $this->notices->cancelPreviousReview($attempt->id);
            }
            $this->missions->updateSubmission($mission, $attempt->id, [
                'decision' => $data['decision'],
                'feedback' => $feedback,
                'reviewer_id' => $guardian->id,
                'reviewed_at' => $now,
                'review_version' => $version,
            ]);
            $this->missions->updateOccurrence($mission, ['status' => $data['decision']]);
            if ($data['decision'] === 'approved' && $previous !== 'approved') {
                $this->ledger->credit($occurrence, $now);
            }
            $this->notices->queue($occurrence, $attempt->id, $data['decision'], $version);
        });

        defer(fn () => $this->notices->deliver($data['submission_id'], $data['decision']))->always();
    }

    public function reopen(User $guardian, string $mission, array $data): void
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);
        $newDue = CarbonImmutable::parse($data['new_due_at'], $guardian->family->timezone)->utc();

        DB::transaction(function () use ($guardian, $mission, $newDue, $data): void {
            $occurrence = $this->missions->find($guardian, $mission, lock: true);
            $now = now();
            if ($newDue->lte($now)) {
                throw ValidationException::withMessages(['new_due_at' => 'Escolha um prazo futuro.']);
            }
            if (! in_array($occurrence->status, ['pending', 'adjustment'], true) || CarbonImmutable::parse($occurrence->due_at, 'UTC')->gt($now)) {
                throw ValidationException::withMessages(['mission' => 'Só é possível reabrir uma missão vencida que aceita entregas.']);
            }

            $this->missions->recordReopening([
                'occurrence_id' => $mission,
                'guardian_id' => $guardian->id,
                'previous_due_at' => $occurrence->due_at,
                'new_due_at' => $newDue,
                'reason' => $data['reason'],
                'reopened_at' => $now,
            ]);
            $this->missions->updateOccurrence($mission, ['due_at' => $newDue]);
        });
    }

    public function photoPath(User $viewer, string $mission, string $submission): ?string
    {
        $this->missions->find($viewer, $mission);

        return $this->missions->photoPath($mission, $submission);
    }

    private function ensureCanSubmit(object $occurrence): void
    {
        $now = now();
        if (! in_array($occurrence->status, ['pending', 'adjustment'], true)
            || CarbonImmutable::parse($occurrence->opens_at, 'UTC')->gt($now)
            || CarbonImmutable::parse($occurrence->due_at, 'UTC')->lte($now)) {
            throw ValidationException::withMessages(['mission' => 'Esta missão não aceita envios neste momento.']);
        }
    }
}
