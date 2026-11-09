<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MissionNotices
{
    public function __construct(private readonly Uazapi $gateway) {}

    public function queue(object $mission, string $submissionId, string $kind): void
    {
        $recipients = User::where('family_id', $mission->family_id)
            ->where('active', true)->where('whatsapp_notices', true);

        if ($kind === 'submitted') {
            $recipients->where('role', User::GUARDIAN)->whereIn('id', DB::table('guardian_child_links')
                ->where('family_id', $mission->family_id)->where('child_id', $mission->child_id)
                ->where('active', true)->select('guardian_id'));
        } else {
            $recipients->whereKey($mission->child_id);
        }

        DB::table('mission_notices')->insertOrIgnore($recipients->pluck('id')->map(fn (string $id) => [
            'recipient_id' => $id,
            'submission_id' => $submissionId,
            'kind' => $kind,
            'available_at' => now(),
        ])->all());
    }

    public function deliver(?string $submissionId = null): void
    {
        $now = now();
        $notices = DB::table('mission_notices');
        (clone $notices)->where('status', 'sending')->where('available_at', '<=', $now->copy()->subMinutes(2))
            ->update(['status' => 'unknown']);
        $pending = (clone $notices)->where('status', 'pending')->where('available_at', '<=', $now)
            ->when($submissionId, fn ($query) => $query->where('submission_id', $submissionId))
            ->orderBy('id')->limit(10)->get();

        foreach ($pending as $notice) {
            $entry = (clone $notices)->where('id', $notice->id);
            if ((clone $entry)->where('status', 'pending')->where('available_at', '<=', now())
                ->update(['status' => 'sending', 'attempts' => $notice->attempts + 1, 'available_at' => now()]) === 0) {
                continue;
            }

            $recipient = User::whereKey($notice->recipient_id)->where('active', true)->where('whatsapp_notices', true)->first();
            $mission = DB::table('mission_submissions as submissions')
                ->join('task_occurrences as missions', 'missions.id', '=', 'submissions.occurrence_id')
                ->where('submissions.id', $notice->submission_id)->first(['missions.id', 'missions.family_id', 'missions.child_id']);

            if (! $recipient || $recipient->family_id !== $mission->family_id
                || ($notice->kind === 'submitted' && ! DB::table('guardian_child_links')
                    ->where('guardian_id', $recipient->id)->where('child_id', $mission->child_id)->where('active', true)->exists())) {
                $entry->update(['status' => 'cancelled']);

                continue;
            }

            $route = $notice->kind === 'submitted' ? 'reviews.show' : 'missions.show';
            $url = rtrim(config('app.url'), '/').route($route, $mission->id, false);
            $message = match ($notice->kind) {
                'submitted' => 'Você tem uma entrega para revisar no DAG.',
                'approved' => 'Uma missão foi aprovada no DAG.',
                'adjustment' => 'Uma missão precisa de um ajuste no DAG.',
            };
            $status = $this->gateway->sendText($recipient->phone, "{$message} {$url}");
            $entry->update([
                'status' => $status === 'pending' && $notice->attempts >= 4 ? 'failed' : $status,
                'available_at' => now()->addMinutes(2 ** ($notice->attempts + 1)),
            ]);
        }
    }
}
