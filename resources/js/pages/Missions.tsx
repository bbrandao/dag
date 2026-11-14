import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import { MissionCard, type MissionSummary } from '../MissionCard';
import { frequencies, type Frequency } from '../tasks';

const statuses = {
    '': 'Todos',
    pending: 'A Fazer',
    approved: 'Concluída',
    expired: 'Vencida',
    in_review: 'Em Revisão',
    adjustment: 'Em Ajuste',
};
type Status = keyof typeof statuses;

export default function Missions({ missions, date, today, frequency, status }: {
    missions: MissionSummary[];
    date: string;
    today: string;
    frequency: Frequency | null;
    status: Status | null;
}) {
    const [selected, setSelected] = useState(date);
    const [selectedStatus, setSelectedStatus] = useState<Status>(status ?? '');
    const [selectedFrequency, setSelectedFrequency] = useState<Frequency | ''>(frequency ?? '');
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const points = missions.reduce((sum, mission) => sum + mission.points, 0);
    const query = new URLSearchParams({ date, ...(status && { status }), ...(frequency && { frequency }) }).toString();

    function showError() {
        setFailed(true);
        return false as const;
    }

    return (
        <main className="screen">
            <Head title="Minhas Missões" />
            <header className="topbar">
                <h1>Minhas Missões</h1>
                <Button asChild className="shrink-0"><Link href="/app"><ArrowLeft size={17} aria-hidden="true" /> Voltar</Link></Button>
            </header>
            <form className="card" aria-labelledby="mission-filters" aria-busy={busy} onSubmit={event => {
                event.preventDefault();
                if (busy) return;
                setBusy(true);
                setFailed(false);
                router.get('/app/missoes', {
                    date: selected,
                    ...(selectedStatus && { status: selectedStatus }),
                    ...(selectedFrequency && { frequency: selectedFrequency }),
                }, {
                    only: ['missions', 'date', 'today', 'status', 'frequency', 'errors'],
                    preserveState: true, preserveScroll: true,
                    onHttpException: showError, onNetworkError: showError, onError: showError,
                    onFinish: () => setBusy(false),
                });
            }}>
                <h2 id="mission-filters">Filtros</h2>
                <div className="field-grid">
                    <label htmlFor="mission-date">Data/Dia
                        <input id="mission-date" type="date" value={selected} max={today} disabled={busy} required
                            onChange={event => setSelected(event.target.value)} />
                    </label>
                    <label htmlFor="mission-status">Status
                        <select id="mission-status" value={selectedStatus} disabled={busy}
                            onChange={event => setSelectedStatus(event.target.value as Status)}>
                            {Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                    <label htmlFor="mission-frequency">Tipo
                        <select id="mission-frequency" value={selectedFrequency} disabled={busy}
                            onChange={event => setSelectedFrequency(event.target.value as Frequency | '')}>
                            <option value="">Todos</option>
                            {Object.entries(frequencies).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </label>
                </div>
                <Button type="submit" disabled={busy} className="mt-2 w-fit cursor-pointer bg-dag-purple text-white hover:bg-[#452497]">
                    {busy && <LoaderCircle size={17} className="motion-safe:animate-spin" aria-hidden="true" />}
                    {busy ? 'Filtrando…' : 'Filtrar'}
                </Button>
                {failed && <p className="error" role="alert">Não foi possível filtrar as missões. Tente novamente.</p>}
            </form>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2" aria-live="polite">
                <Badge className="bg-[#164875] px-3 py-1.5 text-white">{missions.length} {missions.length === 1 ? 'tarefa' : 'tarefas'}</Badge>
                <Badge className="ml-auto px-3 py-1.5">{points} {points === 1 ? 'ponto' : 'pontos'}</Badge>
            </div>
            <section aria-label="Missões encontradas" aria-busy={busy}>
                {missions.length === 0 && <p className="card">Nenhuma missão encontrada para os filtros selecionados.</p>}
                {missions.map(mission => <MissionCard key={mission.id} mission={mission} date={date} query={query} />)}
            </section>
        </main>
    );
}
