<?php

namespace App\Services;

use App\Repositories\MissionNoticeRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class MissionNotices
{
    public function __construct(private readonly Uazapi $gateway, private readonly MissionNoticeRepository $notices) {}

    public function queue(object $mission, string $submissionId, string $kind): void
    {
        $recipients = $this->notices->recipientIds($mission, $kind === 'submitted');
        $this->notices->insert($recipients->map(fn (string $id) => [
            'recipient_id' => $id,
            'submission_id' => $submissionId,
            'kind' => $kind,
            'available_at' => now(),
        ])->all());
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

            if (! $recipient || $recipient->family_id !== $mission->family_id
                || ($notice->kind === 'submitted' && ! $this->notices->guardianIsLinked($recipient->id, $mission->child_id))) {
                $this->notices->update($notice->id, ['status' => 'cancelled']);

                continue;
            }

            $route = $notice->kind === 'submitted' ? 'reviews.show' : 'missions.show';
            $url = rtrim(config('app.url'), '/').route($route, $mission->id, false);
            if ($notice->kind === 'submitted') {
                $timezone = $recipient->family->timezone;
                $deadline = $this->localTime($mission->due_at, $timezone);
                $submittedAt = $this->localTime($mission->submitted_at, $timezone);
                $lines = ['Você tem uma entrega para revisar no DAG:', $mission->title, "deadline: {$deadline}", "concluído em: {$submittedAt}"];
                $comment = $mission->comment;
                $image = $mission->photo_path ? Storage::disk('local')->get($mission->photo_path) : null;
            } else {
                $lines = [match ($notice->kind) {
                    'approved' => 'Sua missão foi aprovada no DAG:',
                    'rejected' => 'Sua missão foi reprovada no DAG:',
                    'adjustment' => 'O responsável pediu um ajuste na sua missão no DAG:',
                }, $mission->title];
                $comment = $mission->feedback;
                $image = null;
            }

            if ($comment !== null && $comment !== '') {
                $lines[] = $comment;
            }
            $message = implode("\n", [...$lines, $url]);
            $status = $image !== null
                ? $this->gateway->sendImage($recipient->phone, $image, $message)
                : $this->gateway->sendText($recipient->phone, $message);
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
