import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
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
    children: string[];
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
    const form = useForm({
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
    });
    const errors = Object.values(form.errors).flat();

    function saveTask(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/guardian/tasks', { onSuccess: () => form.reset('title', 'criteria') });
    }

    return (
        <main className="screen">
            <Head title="Tarefas" />
            <header className="topbar">
                <h1>Tarefas</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            <section className="card" aria-labelledby="new-task">
                <h2 id="new-task">Nova tarefa</h2>
                {children.length === 0 ? (
                    <p><Link href="/app">Cadastre um perfil infantil</Link> para atribuir tarefas.</p>
                ) : (
                    <form onSubmit={saveTask}>
                        <label htmlFor="title">Nome da tarefa</label>
                        <input id="title" value={form.data.title} maxLength={120} required
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
                        <button disabled={form.processing} type="submit">Cadastrar tarefa</button>
                        {form.recentlySuccessful && <p role="status">Tarefa cadastrada.</p>}
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
                    </article>
                ))}
            </section>
        </main>
    );
}
