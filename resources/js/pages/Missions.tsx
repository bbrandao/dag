import { Head, Link } from '@inertiajs/react';
import { type Frequency, type MissionStatus, formatDate, frequencies, missionStatuses } from '../tasks';

interface Mission {
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

const filters: [Frequency | null, string][] = [
    [null, 'Todas'], ['daily', 'Diárias'], ['weekly', 'Semanais'], ['monthly', 'Mensais'], ['yearly', 'Anuais'],
];

export default function Missions({ missions, frequency, today }: {
    missions: Mission[];
    frequency: Frequency | null;
    today: string;
}) {
    const pending = missions.filter(mission => mission.status === 'pending');
    const groups = [
        { title: 'Hoje', missions: pending.filter(mission => mission.due_on === today) },
        { title: 'Próximas', missions: pending.filter(mission => mission.due_on !== today) },
        { title: 'Entregas e retornos', missions: missions.filter(mission => mission.status !== 'pending') },
    ];

    return (
        <main className="screen">
            <Head title="Minhas missões" />
            <header className="topbar">
                <h1>Minhas missões</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            <nav className="filters" aria-label="Filtrar missões por periodicidade">
                {filters.map(([value, label]) => (
                    <Link key={label} className={`button ${frequency === value ? '' : 'secondary'}`}
                        aria-current={frequency === value ? 'page' : undefined}
                        href={value ? `/app/missoes?frequency=${value}` : '/app/missoes'}>{label}</Link>
                ))}
            </nav>
            {missions.length === 0 && <p className="card">Nenhuma missão neste filtro.</p>}
            {groups.filter(group => group.missions.length > 0).map(group => (
                <section key={group.title} aria-label={group.title}>
                    <h2>{group.title}</h2>
                    {group.missions.map(mission => (
                        <article className="card" key={mission.id}>
                            <p className="tag reward">+{mission.points} pontos</p>
                            <h3>{mission.title}</h3>
                            <p>{frequencies[mission.frequency]} · <time dateTime={mission.due_at}>
                                {mission.due_on === today ? 'Hoje' : formatDate(mission.due_on)} até {mission.due_time}
                            </time></p>
                            <p className="criteria">{mission.criteria}</p>
                            <Link className="button secondary" href={`/app/missoes/${mission.id}`}>
                                {mission.status === 'pending' ? 'Concluir' : missionStatuses[
                                    mission.status === 'adjustment' && Date.parse(mission.due_at) <= Date.now() ? 'expired' : mission.status
                                ]}
                            </Link>
                        </article>
                    ))}
                </section>
            ))}
        </main>
    );
}
