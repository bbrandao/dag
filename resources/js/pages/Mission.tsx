import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { formatDate, type MissionStatus, missionStatuses } from '../tasks';

interface MissionDetails {
    id: string;
    title: string;
    status: MissionStatus;
    due: string;
    original_date: string;
    criteria: string;
    points: number;
    photo_mode: 'disabled' | 'optional' | 'required';
}

interface Attempt {
    id: string;
    comment: string | null;
    decision: 'approved' | 'adjustment' | null;
    feedback: string | null;
    photo_url: string | null;
    submitted_at: string;
}

function ShortText({ id, label, value, onChange, required = false }: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    required?: boolean;
}) {
    return (
        <>
            <label htmlFor={id}>{label}</label>
            <textarea id={id} rows={3} required={required} value={value} aria-describedby={`${id}-limit`}
                onChange={event => onChange(Array.from(event.target.value).slice(0, 255).join(''))} />
            <small id={`${id}-limit`}>{Array.from(value).length}/255 caracteres</small>
        </>
    );
}

function SubmissionForm({ mission, requestId }: { mission: MissionDetails; requestId: string }) {
    const form = useForm({ request_id: requestId, comment: '', photo: null as File | null });
    const [preview, setPreview] = useState<string>();
    const photo = form.data.photo;
    useEffect(() => {
        if (!photo) {
            setPreview(undefined);
            return;
        }
        const url = URL.createObjectURL(photo);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [photo]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/app/missoes/${mission.id}/entregas`, { forceFormData: true, onSuccess: () => form.reset('photo', 'comment') });
    }

    return (
        <section className="card">
            <h2>{mission.status === 'adjustment' ? 'Enviar de novo' : 'Enviar para revisão'}</h2>
            <form onSubmit={submit}>
                {mission.photo_mode !== 'disabled' && (
                    <fieldset className="options">
                        <legend>Foto {mission.photo_mode === 'required' ? 'obrigatória' : 'opcional'}</legend>
                        <label>Tirar foto
                            <input type="file" accept="image/*" capture="environment" disabled={form.processing}
                                onChange={event => { const file = event.target.files?.[0]; if (file) form.setData('photo', file); event.target.value = ''; }} />
                        </label>
                        <label>Escolher da galeria
                            <input type="file" accept="image/jpeg,image/png,image/webp" disabled={form.processing}
                                onChange={event => { const file = event.target.files?.[0]; if (file) form.setData('photo', file); event.target.value = ''; }} />
                        </label>
                        {preview && <img className="evidence" src={preview} alt="Prévia da foto escolhida" />}
                        {photo && <button type="button" className="secondary" disabled={form.processing}
                            onClick={() => form.setData('photo', null)}>Remover foto</button>}
                    </fieldset>
                )}
                <ShortText id="comment" label="Comentário opcional" value={form.data.comment} onChange={value => form.setData('comment', value)} />
                <p className="error" role="alert">{Object.values(form.errors).flat().join(' ')}</p>
                {form.progress && <p role="status">Enviando {form.progress.percentage}%</p>}
                <button type="submit" disabled={form.processing}>Enviar para revisão</button>
            </form>
        </section>
    );
}

function GuardianActions({ mission, attempt }: { mission: MissionDetails; attempt: Attempt | undefined }) {
    const review = useForm({ submission_id: attempt?.id ?? '', decision: 'approved', feedback: '' });
    const reopen = useForm({ new_due_at: '', reason: '' });
    const url = `/guardian/revisoes/${mission.id}`;

    if (mission.status === 'in_review' && attempt) {
        return (
            <section className="card">
                <h2>Revisar entrega</h2>
                <form onSubmit={event => { event.preventDefault(); review.post(url); }}>
                    <label htmlFor="decision">Resultado</label>
                    <select id="decision" value={review.data.decision} onChange={event => review.setData('decision', event.target.value)}>
                        <option value="approved">Aprovar</option>
                        <option value="adjustment">Pedir ajuste</option>
                    </select>
                    <ShortText id="feedback" label={review.data.decision === 'adjustment' ? 'O que precisa ajustar?' : 'Mensagem opcional'}
                        value={review.data.feedback} required={review.data.decision === 'adjustment'}
                        onChange={value => review.setData('feedback', value)} />
                    <p className="error" role="alert">{Object.values(review.errors).flat().join(' ')}</p>
                    <button disabled={review.processing} type="submit">Confirmar revisão</button>
                </form>
            </section>
        );
    }

    if (mission.status === 'expired') {
        return (
            <section className="card">
                <h2>Reabrir missão</h2>
                <form onSubmit={event => { event.preventDefault(); reopen.post(`${url}/reabrir`); }}>
                    <label htmlFor="new-due">Novo prazo no horário da família</label>
                    <input id="new-due" type="datetime-local" required value={reopen.data.new_due_at}
                        onChange={event => reopen.setData('new_due_at', event.target.value)} />
                    <ShortText id="reason" label="Motivo" value={reopen.data.reason} required onChange={value => reopen.setData('reason', value)} />
                    <p className="error" role="alert">{Object.values(reopen.errors).flat().join(' ')}</p>
                    <button disabled={reopen.processing} type="submit">Reabrir</button>
                </form>
            </section>
        );
    }

    return null;
}

export default function Mission({ mission, attempts, reopening, requestId, isGuardian }: {
    mission: MissionDetails; attempts: Attempt[]; reopening: string | null; requestId: string; isGuardian: boolean;
}) {
    return (
        <main className="screen">
            <Head title={mission.title} />
            <header className="topbar">
                <h1>{mission.title}</h1>
                <Link className="button secondary" href={isGuardian ? '/guardian/revisoes' : '/app/missoes'}>Voltar</Link>
            </header>
            <section className="card">
                <p className="tag">{missionStatuses[mission.status]} · {mission.points} pontos</p>
                <p>Prazo {mission.due}</p>
                <p className="criteria">{mission.criteria}</p>
                {reopening && <p>Reaberta. Data original {formatDate(mission.original_date)}. {reopening}</p>}
            </section>
            {attempts.length > 0 && <section aria-label="Histórico de entregas">
                {attempts.map(attempt => (
                    <article className="card" key={attempt.id}>
                        <h2>{attempt.decision ? missionStatuses[attempt.decision] : 'Entrega em revisão'}</h2>
                        <p>Enviada em {attempt.submitted_at}</p>
                        {attempt.comment && <p className="criteria">{attempt.comment}</p>}
                        {attempt.photo_url && <img className="evidence" src={attempt.photo_url} alt="Foto da entrega" />}
                        {attempt.feedback && <p className="criteria"><strong>Retorno do responsável</strong><br />{attempt.feedback}</p>}
                    </article>
                ))}
            </section>}
            {isGuardian
                ? <GuardianActions mission={mission} attempt={attempts[0]} key={attempts[0]?.id ?? mission.id} />
                : (mission.status === 'pending' || mission.status === 'adjustment') &&
                    <SubmissionForm mission={mission} requestId={requestId} key={attempts[0]?.id ?? mission.id} />}
        </main>
    );
}
