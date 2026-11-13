import { Head, Link, router, useHttp } from '@inertiajs/react';
import { ArrowLeft, Ban, CalendarDays, Camera, ChevronDown, Clock, LoaderCircle, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import { Avatar, AvatarFallback } from '../components/ui/avatar';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import { Toast, useToast } from '../components/ui/toast';
import { formatDate, frequencies, months, nameInitials, weekDays, type Frequency } from '../tasks';
import type { Task, TaskFormData } from './TaskForm';

const pointsFormat = new Intl.NumberFormat('pt-BR');
const photoLabels: Record<string, string> = { optional: 'Opcional', required: 'Obrigatória', disabled: 'Desativada' };

function searchText(text: string) {
    return text.trim().toLocaleLowerCase('pt-BR').normalize('NFD').replace(/\p{Diacritic}/gu, '');
}

function scheduleLabel(task: Task) {
    const { days, day, month } = task.schedule;

    switch (task.frequency) {
        case 'daily': return days?.length === 7 ? 'Todos os dias' : [...(days ?? [])].sort((a, b) => a - b)
            .map(value => weekDays[value - 1]).join(', ');
        case 'weekly': return day ? weekDays[day - 1] : '';
        case 'monthly': return day ? 'Dia ' + day : 'Último dia do mês';
        case 'yearly': return month ? 'Dia ' + day + ' de ' + months[month - 1].toLocaleLowerCase('pt-BR') : '';
    }
}

export default function Tasks({ tasks, children }: Pick<TaskFormData, 'children'> & { tasks: Task[] }) {
    const confirmation = useRef<HTMLDialogElement>(null);
    const [selected, setSelected] = useState<Task | null>(null);
    const [deleted, setDeleted] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [refreshFailed, setRefreshFailed] = useState(false);
    const { toast, setToast } = useToast();
    const [title, setTitle] = useState('');
    const [frequency, setFrequency] = useState<Frequency | ''>('');
    const [childId, setChildId] = useState('');
    const removal = useHttp<{}, { deleted: boolean }>({});
    const blocked = removal.processing || deleted || refreshing || refreshFailed;
    const query = searchText(title);
    const filtered = tasks.filter(task => searchText(task.title).includes(query)
        && (!frequency || task.frequency === frequency)
        && (!childId || task.child_ids.includes(childId)));
    const total = filtered.reduce((sum, task) => sum + task.points, 0);
    const pointsLabel = total === 1 ? 'ponto' : 'pontos';

    async function removeTask() {
        if (!selected || blocked) return;
        setToast(null);

        try {
            const result = await removal.delete(`/guardian/tasks/${selected.id}`);
            if (!result.deleted) throw new Error('Exclusão não confirmada');
            setDeleted(true);
        } catch {
            setToast({ success: false, text: 'Não foi possível excluir a tarefa. Tente novamente.' });
        } finally {
            confirmation.current?.close();
        }
    }

    function refreshTasks() {
        if (refreshing) return;
        setDeleted(false);
        setRefreshing(true);
        setRefreshFailed(false);
        const onFailure = () => { setRefreshFailed(true); return false as const; };
        router.reload({ only: ['tasks'], onHttpException: onFailure, onNetworkError: onFailure,
            onError: onFailure, onCancel: onFailure, onFinish: () => setRefreshing(false) });
    }

    return <main className="screen">
        <Head title="Tarefas" />
        <header className="topbar flex-wrap">
            <h1>Tarefas</h1>
            <div className="flex flex-wrap gap-2">
                <Button asChild><Link href="/app"><ArrowLeft size={17} aria-hidden="true" /> Voltar</Link></Button>
                <Button asChild className="bg-dag-purple text-white hover:bg-[#452497]">
                    <Link href="/guardian/tasks/nova"><Plus size={17} aria-hidden="true" /> Novo</Link>
                </Button>
            </div>
        </header>
        <section className="card" aria-labelledby="task-filters">
            <h2 id="task-filters">Filtros</h2>
            <label htmlFor="task-title-filter">Título</label>
            <input id="task-title-filter" type="search" value={title} onChange={event => setTitle(event.target.value)} />
            <div className="field-grid mt-3">
                <label>Periodicidade
                    <select value={frequency} onChange={event => setFrequency(event.target.value as Frequency | '')}>
                        <option value="">Todas</option>
                        {Object.entries(frequencies).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                    </select>
                </label>
                <label>Filho
                    <select value={childId} onChange={event => setChildId(event.target.value)}>
                        <option value="">Todos</option>
                        {children.map(child => <option key={child.id} value={child.id}>{child.name}</option>)}
                    </select>
                </label>
            </div>
        </section>
        {refreshing && <p role="status" className="flex items-center gap-2">
            <LoaderCircle size={17} className="motion-safe:animate-spin" aria-hidden="true" /> Atualizando tarefas…
        </p>}
        {refreshFailed && <p role="alert">A tarefa foi excluída. Não foi possível atualizar a lista.{' '}
            <Button type="button" onClick={refreshTasks}>Tentar novamente</Button>
        </p>}
        <section aria-labelledby="registered-tasks" aria-busy={refreshing}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="registered-tasks">Tarefas cadastradas</h2>
                <Badge className="px-3 py-1.5" aria-live="polite">{pointsFormat.format(total)} {pointsLabel}</Badge>
            </div>
            {filtered.length === 0 && <p>{tasks.length ? 'Nenhuma tarefa corresponde aos filtros.' : 'Nenhuma tarefa cadastrada.'}</p>}
            {filtered.map(task => (
                <details className="card group" key={task.id}>
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 [&::-webkit-details-marker]:hidden">
                        <span className="grid min-w-0 justify-items-start gap-2 wrap-anywhere">
                            <Badge className="bg-dag-lilac px-2 py-1">{frequencies[task.frequency]} · {task.points} {task.points === 1 ? 'ponto' : 'pontos'}</Badge>
                            <strong className="text-lg">{task.title}</strong>
                        </span>
                        <ChevronDown className="shrink-0 text-dag-purple group-open:rotate-180" aria-hidden="true" size={20} />
                    </summary>
                    <div className="mt-4 border-0 border-t border-solid border-dag-line pt-4">
                        <p className="criteria">{task.criteria}</p>
                        <p><strong>Periodicidade:</strong> {scheduleLabel(task)}</p>
                        <div className="mb-4 grid grid-cols-[1fr_1fr_auto] items-start gap-2 text-xs sm:text-sm">
                            {[
                                { days: 'Seg a Sex', time: task.weekday_time },
                                { days: 'Sáb e Dom', time: task.weekend_time },
                            ].map(({ days, time }) => (
                                <div key={days} className="grid justify-items-center gap-1">
                                    <span className="inline-flex min-h-5 items-center gap-1 font-semibold tabular-nums">
                                        <Clock size={15} className="shrink-0" aria-hidden="true" /> {time}
                                    </span>
                                    <Badge className="bg-dag-lilac px-2 py-1">{days}</Badge>
                                </div>
                            ))}
                            <div className="grid gap-1">
                                <span className="inline-flex min-h-5 items-center gap-1 tabular-nums">
                                    <CalendarDays size={17} aria-hidden="true" />
                                    <span className="sr-only">Data de início</span>
                                    <time dateTime={task.starts_on}>{formatDate(task.starts_on)}</time>
                                </span>
                                <span className="inline-flex min-h-5 items-center gap-1">
                                    <span className="relative inline-flex" aria-hidden="true">
                                        <Camera size={17} className={task.photo_mode === 'optional' ? 'opacity-45' : undefined} />
                                        {task.photo_mode === 'disabled' && <Ban size={12} className="absolute -right-1 -bottom-0.5 rounded-full bg-white text-red-800" />}
                                    </span>
                                    <span className="sr-only">Foto na entrega</span>
                                    {photoLabels[task.photo_mode]}
                                </span>
                            </div>
                        </div>
                        <ul className="mb-4 flex list-none flex-wrap gap-3 p-0" aria-label="Filhos atribuídos">
                            {task.children.map((name, index) => <li key={task.child_ids[index]} title={name} className="flex">
                                <Avatar aria-hidden="true"><AvatarFallback>{nameInitials(name)}</AvatarFallback></Avatar>
                                <span className="sr-only">{name}</span>
                            </li>)}
                        </ul>
                        {task.can_manage && <div className="flex flex-wrap gap-2">
                            <Button asChild><Link href={`/guardian/tasks/${task.id}/editar`} aria-disabled={blocked || undefined}
                                tabIndex={blocked ? -1 : undefined} className="aria-disabled:opacity-60"
                                onClick={event => { if (blocked) event.preventDefault(); }}>Editar</Link></Button>
                            <Button type="button" className="cursor-pointer bg-red-50 text-red-800 hover:bg-red-100" disabled={blocked}
                                onClick={() => {
                                    setToast(null);
                                    setSelected(task);
                                    confirmation.current?.showModal();
                                }}>Excluir</Button>
                        </div>}
                    </div>
                </details>
            ))}
        </section>
        <dialog ref={confirmation} aria-labelledby="delete-task-title" aria-describedby="delete-task-description"
            aria-busy={removal.processing} onClose={() => setSelected(null)}
            onCancel={event => { if (removal.processing) event.preventDefault(); }}
            className="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-solid border-dag-line bg-white p-5 text-dag-ink shadow-xl backdrop:bg-dag-ink/55">
            <h2 id="delete-task-title">Excluir tarefa?</h2>
            <p id="delete-task-description">“{selected?.title}” deixará de gerar novas missões. As missões já abertas serão preservadas.</p>
            <div className="flex flex-wrap justify-end gap-2">
                <Button type="button" autoFocus disabled={removal.processing} onClick={() => confirmation.current?.close()}>Cancelar</Button>
                <Button type="button" disabled={removal.processing} className="bg-red-800 text-white hover:bg-red-900"
                    onClick={() => { void removeTask(); }}>
                    {removal.processing && <LoaderCircle size={17} className="motion-safe:animate-spin" aria-hidden="true" />}
                    {removal.processing ? 'Excluindo…' : 'Excluir tarefa'}
                </Button>
            </div>
        </dialog>
        <Toast message={deleted ? { success: true, text: 'Tarefa excluída com sucesso.' } : toast}
            onConfirm={deleted ? refreshTasks : undefined} />
    </main>;
}
