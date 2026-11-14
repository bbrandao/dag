import { Head } from '@inertiajs/react';
import { Badge } from '../components/ui/badge';
import { ChildProgressCard, type GuardianProgress } from '../ChildProgressCard';
import { MissionCard, type MissionSummary } from '../MissionCard';

interface DashboardProps {
    role: 'guardian' | 'child';
    family: string;
    today: string;
    progress: GuardianProgress[];
    childMissions: { missions: MissionSummary[] } | null;
}

export default function Dashboard({ role, family, today, progress, childMissions }: DashboardProps) {
    const isGuardian = role === 'guardian';

    return (
        <main className="screen">
            <Head title={isGuardian ? 'Família' : 'Meu espaço'} />
            <h1><Badge className="bg-dag-lilac px-3 py-1.5 text-sm leading-snug wrap-anywhere">{family}</Badge></h1>
            {isGuardian && <h2>Acompanhamento dos Filhos</h2>}
            {isGuardian && progress.length === 0 && <p className="card">Cadastre um perfil infantil para acompanhar as missões e configurar a mesada.</p>}
            {progress.map(child => <ChildProgressCard key={child.id} child={child} today={today} isGuardian={isGuardian} />)}
            {childMissions && <section aria-labelledby="next-missions">
                <h2 id="next-missions">Próximas Missões</h2>
                {childMissions.missions.length === 0 && <p className="card">Não existem mais tarefas pendentes para hoje.</p>}
                {childMissions.missions.map(mission => <MissionCard key={mission.id} mission={mission} date={today} />)}
            </section>}
        </main>
    );
}
