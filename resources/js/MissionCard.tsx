import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { Badge } from './components/ui/badge';
import { formatDate, frequencies, missionStatuses, type Frequency, type MissionStatus } from './tasks';

export interface MissionSummary {
    id: string;
    title: string;
    criteria: string;
    points: number;
    frequency: Frequency;
    due_at: string;
    due_on: string;
    due_time: string;
    status: MissionStatus;
}

const statusIcons: Record<MissionStatus, string> = {
    pending: '○',
    adjustment: '↻',
    in_review: '◷',
    approved: '✓',
    rejected: '×',
    expired: '!',
};

export function MissionStatusBadge({ status, review = false }: { status: MissionStatus; review?: boolean }) {
    return (
        <span className={`tag mission-status status-${status}${review && status === 'pending' ? ' review-pending' : ''}`}>
            <span aria-hidden="true">{statusIcons[status]}</span>
            {status === 'approved' ? (review ? 'Concluída' : 'Concluída · Aprovada') : missionStatuses[status]}
        </span>
    );
}

export function MissionBadges({ status, points, review }: { status: MissionStatus; points: number; review?: boolean }) {
    return <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <MissionStatusBadge status={status} review={review} />
        <Badge className="ml-auto px-3 py-1.5">{points} {points === 1 ? 'ponto' : 'pontos'}</Badge>
    </div>;
}

export function MissionDeadline({ due }: { due: string }) {
    return <p className="flex items-center gap-2 tabular-nums">
        <Clock size={17} className="shrink-0" aria-hidden="true" />
        <span className="sr-only">Prazo</span>
        {due.replace(' ', ' - ')}
    </p>;
}

export function MissionCard({ mission, date, query }: { mission: MissionSummary; date: string; query?: string }) {
    return (
        <article className={`card mission-card status-${mission.status} p-0!`}>
            <Link href={`/app/missoes/${mission.id}${query ? `?${query}` : ''}`}
                className="block rounded-[inherit] p-4 text-dag-ink no-underline hover:bg-dag-lilac/40 sm:p-6">
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <MissionStatusBadge status={mission.status} review />
                    <Badge className="ml-auto px-3 py-1.5">Vale {mission.points} {mission.points === 1 ? 'ponto' : 'pontos'}</Badge>
                </div>
                <h3 className="m-0! wrap-anywhere">{mission.title}</h3>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <Badge className="bg-dag-lilac">{frequencies[mission.frequency]}</Badge>
                    <time className="inline-flex items-center gap-1 text-sm tabular-nums" dateTime={mission.due_at}>
                        <Clock size={16} aria-hidden="true" />
                        {mission.due_on !== date && `${formatDate(mission.due_on)} - `}{mission.due_time}
                    </time>
                </div>
            </Link>
        </article>
    );
}
