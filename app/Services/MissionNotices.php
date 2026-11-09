<?php

namespace App\Services;

use App\Repositories\MissionNoticeRepository;

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

    public function deliver(?string $submissionId = null): void
    {
        $now = now();
        $this->notices->markStaleAsUnknown($now->copy()->subMinutes(2));

        foreach ($this->notices->ready($now, $submissionId) as $notice) {
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
            $message = match ($notice->kind) {
                'submitted' => 'Você tem uma entrega para revisar no DAG.',
                'approved' => 'Uma missão foi aprovada no DAG.',
                'adjustment' => 'Uma missão precisa de um ajuste no DAG.',
            };
            $status = $this->gateway->sendText($recipient->phone, "{$message} {$url}");
            $this->notices->update($notice->id, [
                'status' => $status === 'pending' && $notice->attempts >= 4 ? 'failed' : $status,
                'available_at' => now()->addMinutes(2 ** ($notice->attempts + 1)),
            ]);
        }
    }
}
