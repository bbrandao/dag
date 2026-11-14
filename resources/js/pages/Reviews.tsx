import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import { MissionBadges, MissionDeadline } from '../MissionCard';
import type { MissionStatus } from '../tasks';

const statuses = {
    '': 'Todos',
    pending: 'A Fazer',
    approved: 'Concluídas',
    expired: 'Vencidas',
    in_review: 'Em Revisão',
    adjustment: 'Em Ajuste',
};
type ReviewStatus = keyof typeof statuses;

export default function Reviews({ missions, date, today, child, status, childName }: {
    missions: { id: string; title: string; status: MissionStatus; points: number; due: string; child_name: string }[];
    date: string;
    today: string;
    child: string | null;
    status: ReviewStatus | null;
    childName: string | null;
}) {
    const [selected, setSelected] = useState(date);
    const [selectedStatus, setSelectedStatus] = useState<ReviewStatus>(status ?? '');
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const query = new URLSearchParams({ date, ...(child && { child }), ...(status && { status }) }).toString();

    useEffect(() => {
        setSelected(date);
        setSelectedStatus(status ?? '');
    }, [date, status]);

    function showError() {
        setFailed(true);
        return false as const;
    }

    return (
        <main className="screen">
            <Head title="Revisar Missões" />
            <header className="topbar">
                <div className="min-w-0">
                    <h1 className="mb-1!">Revisar Missões</h1>
                    <p title={childName ?? undefined} className="m-0! truncate text-sm text-dag-muted">
                        {childName ?? 'Filhos da família'}
                    </p>
                </div>
                <Button asChild className="shrink-0"><Link href="/app"><ArrowLeft size={17} aria-hidden="true" /> Voltar</Link></Button>
            </header>
            <form className="card" aria-labelledby="review-filters" aria-busy={busy} onSubmit={event => {
                event.preventDefault();
                if (busy) return;
                setBusy(true);
                setFailed(false);
                router.get('/guardian/revisoes', { date: selected, ...(child && { child }), ...(selectedStatus && { status: selectedStatus }) }, {
                    only: ['missions', 'date', 'today', 'child', 'status', 'childName', 'errors'],
                    preserveState: true, preserveScroll: true,
                    onHttpException: showError, onNetworkError: showError, onError: showError,
                    onFinish: () => setBusy(false),
                });
            }}>
                <h2 id="review-filters">Filtros</h2>
                <div className="field-grid">
                    <label htmlFor="review-date">Data/Dia
                        <input id="review-date" type="date" value={selected} max={today} disabled={busy} required
                            onChange={event => setSelected(event.target.value)} />
                    </label>
                    <label htmlFor="review-status">Status
                        <select id="review-status" value={selectedStatus} disabled={busy}
                            onChange={event => setSelectedStatus(event.target.value as ReviewStatus)}>
                            {Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                </div>
                <Button type="submit" disabled={busy} className="mt-2 w-fit cursor-pointer bg-dag-purple text-white hover:bg-[#452497]">
                    {busy && <LoaderCircle size={17} className="motion-safe:animate-spin" aria-hidden="true" />}
                    {busy ? 'Filtrando…' : 'Filtrar'}
                </Button>
                {failed && <p className="error" role="alert">Não foi possível filtrar as missões. Tente novamente.</p>}
            </form>
            <Badge className="mb-3 bg-[#164875] px-3 py-1.5 text-white" aria-live="polite">
                {missions.length} {missions.length === 1 ? 'tarefa' : 'tarefas'}
            </Badge>
            <section aria-label="Missões do dia" aria-busy={busy}>
                {missions.length === 0 && <p className="card">Nenhuma missão encontrada para os filtros selecionados.</p>}
                {missions.map(mission => (
                    <article className="card" key={mission.id}>
                        <MissionBadges status={mission.status} points={mission.points} review />
                        <h2>{mission.title}</h2>
                        <MissionDeadline due={mission.due} />
                        {!childName && <p>{mission.child_name}</p>}
                        <Button asChild><Link href={`/guardian/revisoes/${mission.id}?${query}`}>Ver missão</Link></Button>
                    </article>
                ))}
            </section>
        </main>
    );
}
