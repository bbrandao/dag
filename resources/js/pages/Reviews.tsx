import { Head, Link } from '@inertiajs/react';
import { type MissionStatus, missionStatuses } from '../tasks';

export default function Reviews({ missions }: {
    missions: { id: string; title: string; status: MissionStatus; due: string; child_name: string }[];
}) {
    return (
        <main className="screen">
            <Head title="Revisar missões" />
            <header className="topbar">
                <h1>Revisar missões</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            {missions.length === 0 && <p className="card">Nenhuma entrega para revisar ou missão vencida para reabrir.</p>}
            {missions.map(mission => (
                <article className="card" key={mission.id}>
                    <p className="tag">{missionStatuses[mission.status]}</p>
                    <h2>{mission.title}</h2>
                    <p>{mission.child_name} · Prazo {mission.due}</p>
                    <Link className="button secondary" href={`/guardian/revisoes/${mission.id}`}>
                        {mission.status === 'in_review' ? 'Ver entrega' : 'Ver missão'}
                    </Link>
                </article>
            ))}
        </main>
    );
}
