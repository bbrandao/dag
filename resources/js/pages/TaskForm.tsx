import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEvent } from 'react';
import { type Frequency, frequencies, months, weekDays } from '../tasks';

export interface Task {
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

export interface TaskFormData {
    children: { id: string; name: string }[];
    today: string;
}

export default function TaskForm({ children, today, task, onSaved, onFailure, onCancel, onBusyChange }: TaskFormData & {
    task?: Task;
    onSaved: () => void;
    onFailure: () => void;
    onCancel?: () => void;
    onBusyChange?: (busy: boolean) => void;
}) {
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
    const form = useForm(task ? {
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
    } : initial);
    const errors = Object.values(form.errors).flat();

    function saveTask(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (form.processing) return;
        const handleFailure = () => { onFailure(); return false as const; };
        const options = {
            preserveScroll: true,
            onStart: () => onBusyChange?.(true),
            onFinish: () => onBusyChange?.(false),
            onSuccess: onSaved,
            onError: onFailure,
            onHttpException: handleFailure,
            onNetworkError: handleFailure,
        };
        if (task) form.patch(`/guardian/tasks/${task.id}`, options);
        else form.post('/guardian/tasks?from=new', options);
    }

    if (children.length === 0) {
        return <p><Link href="/guardian/children">Cadastre um perfil infantil</Link> para atribuir tarefas.</p>;
    }

    return (
        <form onSubmit={saveTask} aria-busy={form.processing}>
            <fieldset disabled={form.processing} className="m-0 grid min-w-0 gap-1.5 border-0 p-0">
                <label htmlFor="title">Nome da tarefa</label>
                <input id="title" autoFocus={Boolean(task)} value={form.data.title} maxLength={120} required
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
                <button disabled={form.processing} type="submit" className="inline-flex items-center justify-center gap-2">
                    {form.processing && <LoaderCircle size={17} className="motion-safe:animate-spin" aria-hidden="true" />}
                    {form.processing ? 'Salvando…' : task ? 'Salvar alterações' : 'Cadastrar tarefa'}
                </button>
                {onCancel && <button className="secondary" type="button" onClick={onCancel}>Cancelar edição</button>}
            </fieldset>
        </form>
    );
}
