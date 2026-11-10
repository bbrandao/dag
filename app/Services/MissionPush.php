<?php

namespace App\Services;

use App\Repositories\PushRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MissionPush
{
    public function __construct(private readonly PushRepository $push, private readonly PushTransport $transport) {}

    public function queueReview(object $mission, string $submission): void
    {
        if (! $this->transport->configured()) {
            return;
        }

        $now = now()->toImmutable();
        $this->push->enqueue($this->push->guardianDevices($mission)->map(fn (object $device) => [
            'subscription_id' => $device->id, 'recipient_id' => $device->user_id,
            'occurrence_id' => $mission->id, 'submission_id' => $submission,
            'event_key' => 'review:'.$submission, 'deadline' => null,
            'expires_at' => $now->addMinutes(15), 'available_at' => $now,
        ])->all());
    }

    public function tick(): void
    {
        if (! $this->transport->configured()) {
            return;
        }

        $now = now()->toImmutable();
        $this->push->prune($now->subDay());
        $this->push->enqueue($this->push->reminders($now, $now->addMinutes(15))->map(function (object $target) use ($now) {
            $deadline = CarbonImmutable::parse($target->due_at, 'UTC');

            return [
                'subscription_id' => $target->id, 'recipient_id' => $target->user_id,
                'occurrence_id' => $target->occurrence_id, 'submission_id' => null,
                'event_key' => hash('sha256', "reminder:{$target->occurrence_id}:{$deadline->getTimestamp()}"),
                'deadline' => $deadline, 'expires_at' => $deadline, 'available_at' => $now,
            ];
        })->all());
        $this->deliver();
    }

    public function deliver(?string $submission = null): void
    {
        if (! $this->transport->configured()) {
            return;
        }

        foreach ($this->push->ready(now(), $submission, $submission === null ? 12 : 4) as $delivery) {
            $now = now()->toImmutable();
            if (! $this->push->claim($delivery, $now)) {
                continue;
            }
            $target = $this->push->target($delivery, $now);
            if ($target === null) {
                $this->push->finish($delivery->id, 'cancelled', $now);

                continue;
            }

            $reminder = $delivery->submission_id === null;
            $payload = [
                'title' => $reminder ? 'DAG · Prazo de tarefa' : 'DAG · Nova entrega',
                'body' => $reminder ? 'Uma tarefa vence nos próximos 15 minutos.' : 'Há uma tarefa aguardando sua revisão.',
                'path' => route($reminder ? 'missions.show' : 'reviews.show', $delivery->occurrence_id, false),
                'tag' => $delivery->event_key,
            ];
            $ttl = max(0, CarbonImmutable::parse($delivery->expires_at, 'UTC')->getTimestamp() - $now->getTimestamp());
            try {
                $subscription = json_decode(Crypt::decryptString($target->subscription), true, flags: JSON_THROW_ON_ERROR);
                $result = $this->transport->send($subscription, $payload, $ttl);
            } catch (Throwable $error) {
                Log::warning('Web Push do DAG falhou.', ['delivery' => $delivery->id, 'error_type' => $error::class]);
                $result = 'failed';
            }

            if ($result === 'expired') {
                $this->push->removeSubscription($delivery->subscription_id, $target->subscription);
            }
            if ($result === 'retry') {
                $result = $delivery->attempts < 2 ? 'pending' : 'failed';
            }
            $this->push->finish($delivery->id, $result, $result === 'pending' ? $now->addMinute() : $now);
        }
    }
}
