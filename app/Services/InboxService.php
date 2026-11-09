<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\InboxRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class InboxService
{
    public function __construct(private readonly InboxRepository $inbox) {}

    public function events(User $user): Collection
    {
        $guardian = $user->role === User::GUARDIAN;

        return $this->inbox->recent($user)
            ->map(fn (object $event) => [
                'id' => $event->id,
                'title' => $event->title,
                'message' => $guardian ? 'Entrega enviada para revisão' : match ($event->decision) {
                    'approved' => 'Missão aprovada',
                    'adjustment' => 'O responsável pediu um ajuste',
                },
                'href' => route($guardian ? 'reviews.show' : 'missions.show', $event->mission_id, false),
                'unread' => $event->read_by === null,
                'occurred_at' => CarbonImmutable::parse($event->occurred_at, 'UTC')->setTimezone($user->family->timezone)->format('d/m/Y H:i'),
            ]);
    }

    public function unread(User $user): int
    {
        return $this->inbox->unread($user);
    }

    public function markRead(User $user, array $ids): void
    {
        $this->inbox->markRead($user, $ids);
    }
}
