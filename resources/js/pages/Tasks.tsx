import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { type Frequency, formatDate, frequencies, months, weekDays } from '../tasks';

interface Task {
    id: string;
    title: string;
    criteria: string;
    points: number;
    frequency: Frequency;
    schedule: { days?: number[]; day?: number | null; month?: number };
    starts_on: string;
    weekday_time: string;
    weekend_time: string;
    photo_mode: string;
    children: string[];
    child_ids: string[];
    can_manage: boolean;
}

function scheduleLabel(task: Task) {
    const { days, day, month } = task.schedule;

    switch (task.frequency) {
        case 'daily': return days?.map(value => weekDays[value - 1]).join(', ');
        case 'weekly': return day ? weekDays[day - 1] : '';
        case 'monthly': return day ? `Dia ${day}` : 'Último dia do mês';
        case 'yearly': return month ? `Dia ${day} de ${months[month - 1].toLowerCase()}` : '';
    }
}

export default function Tasks({ tasks, children, today }: {
    tasks: Task[];
    children: { id: string; name: string }[];
    today: string;
}) {
    const [editing, setEditing] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const titleInput = useRef<HTMLInputElement>(null);
    const initial = {
        title: '',
        criteria: '',
        points: 10,
        frequency: 'daily' as Frequency,
        starts_on: today,
        weekday_time: '19:00',
        weekend_time: '19:00',
        daily_days: [1, 2, 3, 4, 5, 6, 7],
        weekly_day: 1,
        month_day: '',
        year_month: 1,
        child_ids: children.length === 1 ? [children[0].id] : [] as string[],
        photo_mode: 'optional',
    };
    const form = useForm(initial);
    const removal = useForm({});
    const errors = Object.values(form.errors).flat();

    function resetEditor() {
        setEditing(null);
        form.setData(initial);
        form.clearErrors();
    }

    function editTask(task: Task) {
        setEditing(task.id);
        setNotice('');
        form.clearErrors();
        form.setData({
            ...initial,
            title: task.title,
            criteria: task.criteria,
            points: task.points,
            frequency: task.frequency,
            starts_on: task.starts_on,
            weekday_time: task.weekday_time,
            weekend_time: task.weekend_time,
            daily_days: task.schedule.days ?? initial.daily_days,
            weekly_day: task.frequency === 'weekly' ? task.schedule.day ?? 1 : 1,
            month_day: task.frequency === 'monthly' || task.frequency === 'yearly' ? String(task.schedule.day ?? '') : '',
            year_month: task.schedule.month ?? 1,
            child_ids: task.child_ids,
            photo_mode: task.photo_mode,
        });
        titleInput.current?.focus();
    }

    function saveTask(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setNotice('');

        if (editing) {
            form.patch(`/guardian/tasks/${editing}`, { onSuccess: () => {
                resetEditor();
                setNotice('Tarefa atualizada. As missões abertas mantêm as condições anteriores.');
            } });
        } else {
            form.post('/guardian/tasks', { onSuccess: () => {
                form.reset('title', 'criteria');
                setNotice('Tarefa cadastrada.');
            } });
        }
    }

    function removeTask(task: Task) {
        if (!window.confirm(`Excluir “${task.title}”? Ela deixará de gerar novas missões.`)) return;

        setNotice('');
        removal.delete(`/guardian/tasks/${task.id}`, { preserveScroll: true, onSuccess: () => {
            if (editing === task.id) resetEditor();
            setNotice('Tarefa excluída. As missões já abertas foram preservadas.');
        } });
    }

    return (
        <main className="screen">
            <Head title="Tarefas" />
            <header className="topbar">
                <h1>Tarefas</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            <section className="card" aria-labelledby="new-task">
                <h2 id="new-task">{editing ? 'Editar tarefa' : 'Nova tarefa'}</h2>
                {notice && <p role="status">{notice}</p>}
                {children.length === 0 ? (
                    <p><Link href="/app">Cadastre um perfil infantil</Link> para atribuir tarefas.</p>
                ) : (
                    <form onSubmit={saveTask}>
                        <label htmlFor="title">Nome da tarefa</label>
                        <input id="title" ref={titleInput} value={form.data.title} maxLength={120} required
                            placeholder="Arrumar a cama" onChange={event => form.setData('title', event.target.value)} />
                        <label htmlFor="criteria">Como saber se está pronta?</label>
                        <textarea id="criteria" value={form.data.criteria} maxLength={1000} rows={3} required
                            placeholder="Coberta dobrada e travesseiro no lugar."
                            onChange={event => form.setData('criteria', event.target.value)} />
                        <fieldset className="options">
                            <legend>Atribuir a</legend>
                            {children.map(child => (
                                <label className="check" key={child.id}>
                                    <input type="checkbox" checked={form.data.child_ids.includes(child.id)}
                                        onChange={event => form.setData('child_ids', event.target.checked
                                            ? [...form.data.child_ids, child.id]
                                            : form.data.child_ids.filter(id => id !== child.id))} />
                                    {child.name}
                                </label>
                            ))}
                        </fieldset>
                        <div className="field-grid">
                            <label>Pontos
                                <input type="number" min={1} max={100000} required value={form.data.points || ''}
                                    onChange={event => form.setData('points', Number(event.target.value))} />
                            </label>
                            <label>Data de início
                                <input type="date" required value={form.data.starts_on}
                                    onChange={event => form.setData('starts_on', event.target.value)} />
                            </label>
                        </div>
                        <label htmlFor="frequency">Periodicidade</label>
                        <select id="frequency" value={form.data.frequency}
                            onChange={event => form.setData('frequency', event.target.value as Frequency)}>
                            {Object.entries(frequencies).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                        </select>
                        {form.data.frequency === 'daily' && (
                            <fieldset className="options">
                                <legend>Dias em que a missão aparece</legend>
                                <div className="days">
                                    {weekDays.map((day, index) => (
                                        <label key={day} className="check">
                                            <input type="checkbox" checked={form.data.daily_days.includes(index + 1)}
                                                onChange={event => form.setData('daily_days', event.target.checked
                                                    ? [...form.data.daily_days, index + 1].sort((a, b) => a - b)
                                                    : form.data.daily_days.filter(value => value !== index + 1))} />
                                            {day}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        )}
                        {form.data.frequency === 'weekly' && (
                            <label>Dia da semana
                                <select value={form.data.weekly_day}
                                    onChange={event => form.setData('weekly_day', Number(event.target.value))}>
                                    {weekDays.map((day, index) => <option key={day} value={index + 1}>{day}</option>)}
                                </select>
                            </label>
                        )}
                        {form.data.frequency === 'yearly' && (
                            <label>Mês
                                <select value={form.data.year_month}
                                    onChange={event => form.setData('year_month', Number(event.target.value))}>
                                    {months.map((month, index) => <option key={month} value={index + 1}>{month}</option>)}
                                </select>
                            </label>
                        )}
                        {(form.data.frequency === 'monthly' || form.data.frequency === 'yearly') && (
                            <label>{form.data.frequency === 'monthly' ? 'Dia do mês (em branco para o último dia)' : 'Dia do mês'}
                                <input type="number" min={1} max={31} required={form.data.frequency === 'yearly'}
                                    value={form.data.month_day} onChange={event => form.setData('month_day', event.target.value)} />
                            </label>
                        )}
                        <div className="field-grid">
                            <label>Prazo de segunda a sexta
                                <input type="time" required value={form.data.weekday_time}
                                    onChange={event => form.setData('weekday_time', event.target.value)} />
                            </label>
                            <label>Prazo aos sábados e domingos
                                <input type="time" required value={form.data.weekend_time}
                                    onChange={event => form.setData('weekend_time', event.target.value)} />
                            </label>
                        </div>
                        <label htmlFor="photo-mode">Foto na entrega</label>
                        <select id="photo-mode" value={form.data.photo_mode}
                            onChange={event => form.setData('photo_mode', event.target.value)}>
                            <option value="optional">Opcional</option>
                            <option value="required">Obrigatória</option>
                            <option value="disabled">Desativada</option>
                        </select>
                        {errors.length > 0 && (
                            <ul className="error" role="alert">{errors.map((error, index) => <li key={index}>{error}</li>)}</ul>
                        )}
                        <button disabled={form.processing} type="submit">{editing ? 'Salvar alterações' : 'Cadastrar tarefa'}</button>
                        {editing && <button className="secondary" type="button" disabled={form.processing}
                            onClick={resetEditor}>Cancelar edição</button>}
                    </form>
                )}
            </section>
            <section aria-labelledby="registered-tasks">
                <h2 id="registered-tasks">Tarefas cadastradas</h2>
                {tasks.length === 0 && <p>Nenhuma tarefa cadastrada.</p>}
                {tasks.map(task => (
                    <article className="card" key={task.id}>
                        <p className="tag">{frequencies[task.frequency]} · {task.points} pontos</p>
                        <h3>{task.title}</h3>
                        <p className="criteria">{task.criteria}</p>
                        <p>{scheduleLabel(task)} · A partir de {formatDate(task.starts_on)}</p>
                        <p>Seg–sex até {task.weekday_time} · Sáb–dom até {task.weekend_time}</p>
                        <p>{task.children.join(', ')}</p>
                        {task.can_manage && (
                            <div className="filters">
                                <button className="secondary" type="button" disabled={form.processing || removal.processing}
                                    onClick={() => editTask(task)}>Editar</button>
                                <button className="secondary" type="button" disabled={form.processing || removal.processing}
                                    onClick={() => removeTask(task)}>Excluir</button>
                            </div>
                        )}
                    </article>
                ))}
            </section>
        </main>
    );
}
