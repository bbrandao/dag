import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Ban, CalendarDays, Camera, ChevronDown, Clock, Plus } from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback } from '../components/ui/avatar';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import { formatDate, frequencies, months, nameInitials, weekDays, type Frequency } from '../tasks';
import TaskForm, { type Task, type TaskFormData } from './TaskForm';

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

export default function Tasks({ tasks, children, today }: TaskFormData & { tasks: Task[] }) {
    const [editing, setEditing] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const [title, setTitle] = useState('');
    const [frequency, setFrequency] = useState<Frequency | ''>('');
    const [childId, setChildId] = useState('');
    const removal = useForm({});
    const query = searchText(title);
    const filtered = tasks.filter(task => searchText(task.title).includes(query)
        && (!frequency || task.frequency === frequency)
        && (!childId || task.child_ids.includes(childId)));
    const total = filtered.reduce((sum, task) => sum + task.points, 0);
    const pointsLabel = total === 1 ? 'ponto' : 'pontos';

    function removeTask(task: Task) {
        if (removal.processing || !window.confirm('Excluir “' + task.title + '”? Ela deixará de gerar novas missões.')) return;
        setNotice('');
        removal.delete('/guardian/tasks/' + task.id, { preserveScroll: true, onSuccess: () => {
            if (editing === task.id) setEditing(null);
            setNotice('Tarefa excluída. As missões já abertas foram preservadas.');
        } });
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
        {notice && <p role="status">{notice}</p>}
        <section aria-labelledby="registered-tasks">
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
                        <div className="mb-4 flex justify-between gap-4">
                            {[
                                { days: 'Seg a Sex', time: task.weekday_time },
                                { days: 'Sáb e Dom', time: task.weekend_time },
                            ].map(({ days, time }) => (
                                <div key={days} className="grid justify-items-center gap-1">
                                    <span className="inline-flex items-center gap-1.5 text-sm font-semibold">
                                        <Clock size={15} aria-hidden="true" /> {time}
                                    </span>
                                    <Badge className="bg-dag-lilac px-2 py-1">{days}</Badge>
                                </div>
                            ))}
                        </div>
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm">
                            <span className="inline-flex items-center gap-2">
                                <CalendarDays size={17} aria-hidden="true" />
                                <span className="sr-only">Data de início</span>
                                <time dateTime={task.starts_on}>{formatDate(task.starts_on)}</time>
                            </span>
                            <span className="inline-flex items-center gap-2">
                                <span className="relative inline-flex" aria-hidden="true">
                                    <Camera size={17} className={task.photo_mode === 'optional' ? 'opacity-45' : undefined} />
                                    {task.photo_mode === 'disabled' && <Ban size={12} className="absolute -right-1 -bottom-0.5 rounded-full bg-white text-red-800" />}
                                </span>
                                <span className="sr-only">Foto na entrega</span>
                                {photoLabels[task.photo_mode]}
                            </span>
                        </div>
                        <ul className="mb-4 flex list-none flex-wrap gap-3 p-0" aria-label="Filhos atribuídos">
                            {task.children.map((name, index) => <li key={task.child_ids[index]} title={name} className="flex">
                                <Avatar aria-hidden="true"><AvatarFallback>{nameInitials(name)}</AvatarFallback></Avatar>
                                <span className="sr-only">{name}</span>
                            </li>)}
                        </ul>
                        {task.can_manage && (editing === task.id
                            ? <TaskForm task={task} children={children} today={today} onCancel={() => setEditing(null)}
                                onSaved={() => {
                                    setEditing(null);
                                    setNotice('Tarefa atualizada. As missões abertas mantêm as condições anteriores.');
                                }}
                                onFailure={() => setNotice('Não foi possível atualizar a tarefa. Confira os dados e tente novamente.')} />
                            : <div className="flex flex-wrap gap-2">
                                <Button type="button" className="cursor-pointer" disabled={removal.processing} onClick={() => {
                                    setEditing(task.id);
                                    setNotice('');
                                }}>Editar</Button>
                                <Button type="button" className="cursor-pointer bg-red-50 text-red-800 hover:bg-red-100" disabled={removal.processing}
                                    onClick={() => removeTask(task)}>Excluir</Button>
                            </div>)}
                    </div>
                </details>
            ))}
        </section>
    </main>;
}
