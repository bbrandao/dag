<?php

namespace App\Services;

use App\Repositories\MissionNoticeRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class MissionNotices
{
    public function __construct(private readonly Uazapi $gateway, private readonly MissionNoticeRepository $notices) {}

    public function queue(object $mission, string $submissionId, string $kind, int $version = 0): void
    {
        $recipients = $this->notices->recipientIds($mission, $kind === 'submitted');
        $this->notices->insert($recipients->map(fn (string $id) => [
            'recipient_id' => $id,
            'submission_id' => $submissionId,
            'kind' => $kind,
            'review_version' => $version,
            'available_at' => now(),
        ])->all());
    }

    public function cancelPreviousReview(string $submission): void
    {
        $this->notices->cancelPreviousReview($submission);
    }

    public function deliver(?string $submissionId = null, ?string $kind = null): void
    {
        $now = now();
        $this->notices->markStaleAsUnknown($now->copy()->subMinutes(2));

        foreach ($this->notices->ready($now, $submissionId, $kind) as $notice) {
            if (! $this->notices->claim($notice)) {
                continue;
            }

            $recipient = $this->notices->recipient($notice->recipient_id);
            $mission = $this->notices->mission($notice->submission_id);
            $submitted = $notice->kind === 'submitted';

            if (! $recipient || $recipient->family_id !== $mission->family_id
                || ($submitted && ! $this->notices->guardianIsLinked($recipient->id, $mission->child_id))
                || (! $submitted && (int) $mission->review_version !== (int) $notice->review_version)) {
                $this->notices->update($notice->id, ['status' => 'cancelled']);

                continue;
            }

            $route = $submitted ? 'reviews.show' : 'missions.show';
            $baseUrl = config('dag.public_url') ?: config('app.url');
            $url = rtrim($baseUrl, '/').route($route, $mission->id, false);
            $title = str_replace(["\r", "\n"], ' ', $mission->title);
            if ($submitted) {
                $timezone = $recipient->family->timezone;
                $deadline = $this->localTime($mission->due_at, $timezone);
                $submittedAt = $this->localTime($mission->submitted_at, $timezone);
                $message = "*DAG: Tarefa para revisão 📝*\n\n> {$title}\n\n🕒 Prazo: {$deadline}\n✅ Concluída em: {$submittedAt}";
                $comment = $mission->comment;
                $image = $mission->photo_path ? Storage::disk('local')->get($mission->photo_path) : null;
            } else {
                $heading = match ($notice->kind) {
                    'approved' => 'Missão APROVADA! ✅',
                    'rejected' => 'Missão REPROVADA! 🚫',
                    'adjustment' => 'Missão precisa de AJUSTE! 📝',
                };
                $message = "*DAG: {$heading}*\n\n> {$title}";
                $comment = $mission->feedback;
                $image = null;
            }

            if ($comment !== null && $comment !== '') {
                $message .= "\n\n_{$comment}_";
            }
            $status = $this->gateway->sendLinkButton(
                $recipient->phone, $message, $submitted ? 'Revisar' : 'Ver no DAG', $url, $image,
            );
            $this->notices->update($notice->id, [
                'status' => $status === 'pending' && $notice->attempts >= 4 ? 'failed' : $status,
                'available_at' => now()->addSeconds($notice->attempts === 0 ? 30 : 60 * 2 ** ($notice->attempts + 1)),
            ]);
        }
    }

    private function localTime(string $value, string $timezone): string
    {
        return CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('d/m/Y H:i');
    }
}
