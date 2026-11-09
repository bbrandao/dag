import { Link } from '@inertiajs/react';
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

export function MissionStatusBadge({ status }: { status: MissionStatus }) {
    return (
        <span className={`tag mission-status status-${status}`}>
            <span aria-hidden="true">{statusIcons[status]}</span>
            {status === 'approved' ? 'Concluída · Aprovada' : missionStatuses[status]}
        </span>
    );
}

export function MissionCard({ mission, today }: { mission: MissionSummary; today: string }) {
    const expired = mission.status === 'expired';
    const actionable = mission.status === 'pending' || mission.status === 'adjustment';
    const label = mission.status === 'pending' ? 'Concluir' : mission.status === 'adjustment' ? 'Enviar ajuste' : 'Ver detalhes';

    return (
        <article className={`card mission-card status-${mission.status}`}>
            <div className="mission-card-labels">
                <MissionStatusBadge status={mission.status} />
                {actionable && <span className="tag reward">Vale {mission.points} pontos</span>}
                {mission.status === 'approved' && <span className="tag mission-status status-approved">+{mission.points} pontos conquistados</span>}
            </div>
            <h3>{mission.title}</h3>
            <p>{frequencies[mission.frequency]} · <time dateTime={mission.due_at}>
                {mission.due_on === today ? 'Hoje' : formatDate(mission.due_on)} até {mission.due_time}
            </time></p>
            <p className="criteria">{mission.criteria}</p>
            {expired && <p>Prazo encerrado. Aguarde a reabertura pelo responsável.</p>}
            {mission.status === 'in_review' && <p>Entrega enviada. Aguardando o responsável.</p>}
            <Link className={actionable ? 'button' : 'button secondary'} href={`/app/missoes/${mission.id}`}>{label}</Link>
        </article>
    );
}
