import { Head, Link, router, useForm } from '@inertiajs/react';
import ChildProfileForm from './ChildProfileForm';
import PushPreferences from '../PushPreferences';
import { MissionCard, type MissionSummary } from '../MissionCard';

const reais = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const money = (cents: number) => reais.format(cents / 100);

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
}

interface DashboardProps {
    name: string;
    role: 'guardian' | 'child';
    family: string;
    children: { id: string; name: string }[];
    whatsappNotices: boolean;
    earnedPoints: number;
    monthLabel: string;
    rateCents: number | null;
    progress: Progress[];
    pushPublicKey: string | null;
    childMissions: { missions: MissionSummary[]; today: string } | null;
}

export default function Dashboard({ name, role, family, children, whatsappNotices, earnedPoints, monthLabel, rateCents, progress, childMissions, pushPublicKey }: DashboardProps) {
    const notices = useForm({ enabled: whatsappNotices });
    const isGuardian = role === 'guardian';
    const firstChild = children[0];

    return (
        <main className="screen">
            <Head title={isGuardian ? 'Família' : 'Meu espaço'} />
            <header className="topbar">
                <p className="brand"><span aria-hidden="true">✓</span> Do And Gain</p>
                <button type="button" className="secondary" onClick={() => router.post('/logout')}>Sair</button>
            </header>
            <section className="card">
                <p className="tag">{isGuardian ? 'Responsável' : 'Meu espaço'}</p>
                <h1>Olá, {name}!</h1>
                <p>{isGuardian ? family : 'Você entrou no seu perfil.'}</p>
                {!isGuardian && <p className="tag reward">{earnedPoints} pontos conquistados</p>}
                <div className="filters">
                    <Link className="button" href={isGuardian ? '/guardian/tasks' : '/app/missoes'}>
                        {isGuardian ? 'Gerenciar tarefas' : 'Ver minhas missões'}
                    </Link>
                    {isGuardian && <Link className="button secondary" href="/guardian/revisoes">Revisar missões</Link>}
                    {!isGuardian && <Link className="button secondary" href="/app/ganhos">Ver meus ganhos</Link>}
                </div>
            </section>
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
            {isGuardian && firstChild && (
                <section className="card">
                    <h2>Valor de cada ponto</h2>
                    <p>{rateCents === null ? 'Configure a taxa de conversão para transformar os pontos da família em reais.' : `Taxa da família: 1 ponto = ${money(rateCents)}.`}</p>
                    <a className="button" href={`/guardian/mesadas/${firstChild.id}#conversao`}>Configurar valor do ponto</a>
                </section>
            )}
            <section aria-labelledby="progress-title">
                <h2 id="progress-title">{isGuardian ? 'Acompanhamento dos filhos' : 'Meu acompanhamento'}</h2>
                <p>Resumo de {monthLabel}</p>
                {progress.length === 0 && <p className="card">Cadastre um perfil infantil para acompanhar as missões e configurar a mesada.</p>}
                {progress.map(item => {
                    const allowance = isGuardian ? `/guardian/mesadas/${item.id}` : '/app/ganhos';

                    return (
                        <article className="card" key={item.id}>
                            {isGuardian && <h3>{item.name}</h3>}
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
                                {isGuardian && <a className="button" href={`${allowance}#fechamento`}>Encerrar ciclo de mesada</a>}
                                <a className="button secondary" href={`${allowance}#evolucao`}>Ver evolução e extrato</a>
                            </div>
                        </article>
                    );
                })}
            </section>
            <section className="card">
                <h2>Avisos</h2>
                <form onSubmit={event => { event.preventDefault(); notices.patch('/app/avisos'); }}>
                    <label className="check">
                        <input type="checkbox" checked={notices.data.enabled}
                            onChange={event => notices.setData('enabled', event.target.checked)} />
                        {isGuardian ? 'Avisar pelo WhatsApp quando houver uma entrega' : 'Receber o resultado da revisão pelo WhatsApp'}
                    </label>
                    <button type="submit" disabled={notices.processing || notices.data.enabled === whatsappNotices}>Salvar preferência</button>
                    {notices.recentlySuccessful && <p role="status">Preferência salva.</p>}
                </form>
            </section>
            {pushPublicKey && <PushPreferences publicKey={pushPublicKey} guardian={isGuardian} />}
            {isGuardian && (
                <section className="card" aria-labelledby="children-title">
                    <h2 id="children-title">Filhos vinculados</h2>
                    {children.length ? (
                        <ul>{children.map(child => (
                            <li key={child.id}>
                                {child.name} <Link href={`/guardian/children/${child.id}/editar`} aria-label={`Editar perfil de ${child.name}`}>Editar</Link>
                                {' · '}<Link href={`/guardian/mesadas/${child.id}`} aria-label={`Ver mesadas de ${child.name}`}>Mesadas</Link>
                            </li>
                        ))}</ul>
                    ) : <p>Cadastre o primeiro perfil da família.</p>}
                    <ChildProfileForm />
                </section>
            )}
        </main>
    );
}
