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
        return $this->inbox->recent($user)->map(fn (object $event) => $this->format($user, $event));
    }

    public function unreadPage(User $user): array
    {
        $page = $this->inbox->unreadPage($user);

        return [
            'events' => collect($page->items())->map(fn (object $event) => $this->format($user, $event)),
            'previous' => $page->previousPageUrl(),
            'next' => $page->nextPageUrl(),
        ];
    }

    public function summary(User $user): array
    {
        $preview = $this->inbox->unreadPreview($user);

        return [
            'unread' => (int) ($preview->first()->unread_total ?? 0),
            'events' => $preview->map(fn (object $event) => $this->format($user, $event)),
        ];
    }

    private function format(User $user, object $event): array
    {
        $guardian = $user->role === User::GUARDIAN;

        return [
            'id' => $event->id,
            'title' => $event->title,
            'message' => $guardian ? 'Entrega enviada para revisão' : match ($event->decision) {
                'approved' => 'Missão aprovada',
                'rejected' => 'Missão reprovada',
                'adjustment' => 'O responsável pediu um ajuste',
            },
            'href' => route($guardian ? 'reviews.show' : 'missions.show', $event->mission_id, false),
            'unread' => $event->read_by === null,
            'occurred_at' => CarbonImmutable::parse($event->occurred_at, 'UTC')->setTimezone($user->family->timezone)->format('d/m/Y H:i'),
        ];
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
