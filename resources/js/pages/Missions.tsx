import { Head, Link } from '@inertiajs/react';
import { type Frequency } from '../tasks';
import { MissionCard, type MissionSummary } from '../MissionCard';

const filters: [Frequency | null, string][] = [
    [null, 'Todas'], ['daily', 'Diárias'], ['weekly', 'Semanais'], ['monthly', 'Mensais'], ['yearly', 'Anuais'],
];

export default function Missions({ missions, frequency, today }: {
    missions: MissionSummary[];
    frequency: Frequency | null;
    today: string;
}) {
    const open = missions.filter(mission => mission.status === 'pending' || mission.status === 'adjustment');
    const groups = [
        { title: 'Hoje', missions: open.filter(mission => mission.due_on === today) },
        { title: 'Próximas', missions: open.filter(mission => mission.due_on !== today) },
        { title: 'Vencidas', missions: missions.filter(mission => mission.status === 'expired').reverse() },
        { title: 'Entregas e retornos', missions: missions.filter(mission => ['in_review', 'approved', 'rejected'].includes(mission.status)) },
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
                    {group.missions.map(mission => <MissionCard key={mission.id} mission={mission} today={today} />)}
                </section>
            ))}
        </main>
    );
}
