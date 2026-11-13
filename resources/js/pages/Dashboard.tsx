import { Head, Link } from '@inertiajs/react';
import { Badge } from '../components/ui/badge';
import { ChildProgressCard, type GuardianProgress } from '../ChildProgressCard';
import { MissionCard, type MissionSummary } from '../MissionCard';

import { money } from '../money';

interface Progress {
    id: string;
    name: string;
    open: number;
    inReview: number;
    expired: number;
    approved: number;
    availablePoints: number;
    awaitingConversion: boolean;
    monthCents: number;
    monthAwaitingConversion: boolean;
    pendingCents: number;
    paidThisMonth: number;
    availableCents: number;
    periods: GuardianProgress['periods'] | null;
}

interface DashboardProps {
    role: 'guardian' | 'child';
    family: string;
    earnedPoints: number;
    monthLabel: string;
    today: string;
    progress: Progress[];
    childMissions: { missions: MissionSummary[]; today: string } | null;
}

export default function Dashboard({ role, family, earnedPoints, monthLabel, today, progress, childMissions }: DashboardProps) {
    const isGuardian = role === 'guardian';

    return (
        <main className="screen">
            <Head title={isGuardian ? 'Família' : 'Meu espaço'} />
            <h1><Badge className="bg-dag-lilac px-3 py-1.5 text-sm leading-snug wrap-anywhere">{family}</Badge></h1>
            {!isGuardian && <section className="card">
                <p className="tag reward">{earnedPoints} pontos conquistados</p>
                <div className="filters">
                    <Link className="button" href="/app/missoes">Ver minhas missões</Link>
                    <Link className="button secondary" href="/app/ganhos">Ver meus ganhos</Link>
                </div>
            </section>}
            {childMissions && [
                { title: 'Missões a fazer', missions: childMissions.missions.filter(mission => mission.status === 'pending' || mission.status === 'adjustment') },
                { title: 'Em revisão', missions: childMissions.missions.filter(mission => mission.status === 'in_review') },
                { title: 'Missões vencidas', missions: childMissions.missions.filter(mission => mission.status === 'expired') },
                { title: 'Missões concluídas', missions: childMissions.missions.filter(mission => mission.status === 'approved') },
                { title: 'Missões reprovadas', missions: childMissions.missions.filter(mission => mission.status === 'rejected') },
            ].filter(group => group.missions.length > 0).map(group => (
                <section key={group.title} aria-label={group.title}>
                    <h2>{group.title}</h2>
                    {group.missions.map(mission => <MissionCard key={mission.id} mission={mission} today={childMissions.today} />)}
                </section>
            ))}
            <section aria-labelledby="progress-title">
                <h2 id="progress-title">{isGuardian ? 'Acompanhamento dos Filhos' : 'Meu acompanhamento'}</h2>
                {!isGuardian && <p>Resumo de {monthLabel}</p>}
                {progress.length === 0 && <p className="card">Cadastre um perfil infantil para acompanhar as missões e configurar a mesada.</p>}
                {progress.map(item => {
                    if (isGuardian) {
                        return item.periods && <ChildProgressCard key={item.id} child={{ ...item, periods: item.periods }} today={today} />;
                    }

                    return (
                        <article className="card" key={item.id}>
                            <dl className="dashboard-metrics">
                                <div><dt>Missões a fazer agora</dt><dd>{item.open}</dd></div>
                                <div><dt>Em revisão agora</dt><dd>{item.inReview}</dd></div>
                                <div><dt>Aprovadas no mês</dt><dd>{item.approved}</dd></div>
                                <div><dt>Vencidas no mês</dt><dd>{item.expired}</dd></div>
                                <div><dt>Pontos disponíveis</dt><dd>{item.availablePoints}</dd></div>
                                <div><dt>Ganhos no mês</dt><dd>{item.monthAwaitingConversion ? 'Aguardando taxa' : money(item.monthCents)}</dd></div>
                                <div><dt>Mesadas a receber</dt><dd>{money(item.pendingCents)}</dd></div>
                                <div><dt>Pago no mês</dt><dd>{money(item.paidThisMonth)}</dd></div>
                            </dl>
                            {item.awaitingConversion && <p>Há pontos disponíveis aguardando a primeira taxa de conversão.</p>}
                            <div className="filters">
                                <a className="button secondary" href="/app/ganhos#evolucao">Ver evolução e extrato</a>
                            </div>
                        </article>
                    );
                })}
            </section>
        </main>
    );
}
