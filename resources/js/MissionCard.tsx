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

export function MissionCard({ mission, today }: { mission: MissionSummary; today: string }) {
    const expired = mission.status === 'expired';
    const label = mission.status === 'pending' ? 'Concluir' : mission.status === 'adjustment' ? 'Enviar ajuste' : 'Ver detalhes';

    return (
        <article className={expired ? 'card mission-expired' : 'card'}>
            <p className={expired ? 'tag tag-expired' : 'tag reward'}>
                {expired ? missionStatuses.expired : `+${mission.points} pontos`}
            </p>
            <h3>{mission.title}</h3>
            <p>{frequencies[mission.frequency]} · <time dateTime={mission.due_at}>
                {mission.due_on === today ? 'Hoje' : formatDate(mission.due_on)} até {mission.due_time}
            </time></p>
            <p className="criteria">{mission.criteria}</p>
            {expired && <p>Prazo encerrado. Aguarde a reabertura pelo responsável.</p>}
            {!expired && mission.status !== 'pending' && <p>{missionStatuses[mission.status]}</p>}
            <Link className="button secondary" href={`/app/missoes/${mission.id}`}>{label}</Link>
        </article>
    );
}
