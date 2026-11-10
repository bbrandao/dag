import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { type MissionStatus, missionStatuses } from '../tasks';

export default function Reviews({ missions, date, today }: {
    missions: { id: string; title: string; status: MissionStatus; due: string; child_name: string; submitted_at: string | null }[];
    date: string | null;
    today: string;
}) {
    const [selected, setSelected] = useState(date ?? today);

    return (
        <main className="screen">
            <Head title="Revisar missões" />
            <header className="topbar">
                <h1>Revisar missões</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            <form className="card" onSubmit={event => {
                event.preventDefault();
                router.get('/guardian/revisoes', { date: selected });
            }}>
                <label htmlFor="review-date">Consultar tarefas de um dia</label>
                <input id="review-date" type="date" value={selected} max={today} required onChange={event => setSelected(event.target.value)} />
                <div className="filters">
                    <button type="submit">Consultar dia</button>
                    {date && <Link className="button secondary" href="/guardian/revisoes">Ver fila de revisão</Link>}
                </div>
            </form>
            {missions.length === 0 && <p className="card">{date ? 'Nenhuma missão encontrada para esse dia.' : 'Nenhuma entrega para revisar ou missão vencida para reabrir.'}</p>}
            {missions.map(mission => (
                <article className="card" key={mission.id}>
                    <p className="tag">{missionStatuses[mission.status]}</p>
                    <h2>{mission.title}</h2>
                    <p>{mission.child_name} · Prazo {mission.due}</p>
                    {mission.submitted_at && <p>Última entrega em {mission.submitted_at}</p>}
                    <Link className="button secondary" href={`/guardian/revisoes/${mission.id}${date ? `?date=${date}` : ''}`}>
                        {mission.status === 'in_review' ? 'Ver entrega' : 'Ver missão'}
                    </Link>
                </article>
            ))}
        </main>
    );
}
