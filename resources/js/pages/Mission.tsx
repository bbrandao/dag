import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { formatDate, type MissionStatus, missionStatuses } from '../tasks';
import { MissionStatusBadge } from '../MissionCard';
import { encodePhoto, snapshotPhoto } from '../photos';

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
    decision: 'approved' | 'rejected' | 'adjustment' | null;
    feedback: string | null;
    photo_url: string | null;
    submitted_at: string;
    review_version: number;
}

async function preparePhoto(file: File): Promise<File> {
    const url = URL.createObjectURL(file);
    try {
        const image = new Image();
        image.src = url;
        await image.decode();
        return await encodePhoto(image, image.naturalWidth, image.naturalHeight) ?? file;
    } catch {
        // Formatos que o navegador não decodifica seguem para o servidor.
        return file;
    } finally {
        URL.revokeObjectURL(url);
    }
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
    const [preparing, setPreparing] = useState(false);
    const [previewFailed, setPreviewFailed] = useState(false);
    const [cameraOpen, setCameraOpen] = useState(false);
    const [cameraReady, setCameraReady] = useState(false);
    const [cameraFailed, setCameraFailed] = useState(false);
    const videoRef = useRef<HTMLVideoElement>(null);
    const cameraRequest = useRef<AbortController | null>(null);
    const supportsCamera = Boolean(navigator.mediaDevices?.getUserMedia);
    const photo = form.data.photo;

    useEffect(() => () => cameraRequest.current?.abort(), []);

    useEffect(() => {
        if (!photo) {
            setPreview(undefined);
            return;
        }
        const url = URL.createObjectURL(photo);
        setPreviewFailed(false);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [photo]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (preparing || cameraOpen) return;
        form.post(`/app/missoes/${mission.id}/entregas`, { forceFormData: true, onSuccess: () => form.reset('photo', 'comment') });
    }

    function closeCamera() {
        cameraRequest.current?.abort();
        if (videoRef.current) videoRef.current.srcObject = null;
        setCameraOpen(false);
        setCameraReady(false);
    }

    async function openCamera() {
        const request = new AbortController();
        cameraRequest.current = request;
        setCameraFailed(false);
        setCameraOpen(true);
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' } } });
            const video = videoRef.current;
            const stop = () => stream.getTracks().forEach(track => track.stop());
            if (request.signal.aborted || !video) {
                stop();
                return;
            }
            request.signal.addEventListener('abort', stop, { once: true });
            video.srcObject = stream;
            await video.play();
            if (!request.signal.aborted) setCameraReady(true);
        } catch {
            if (request.signal.aborted) return;
            closeCamera();
            setCameraFailed(true);
        }
    }

    async function takePhoto() {
        const video = videoRef.current;
        if (!video?.videoWidth || !video.videoHeight) return;
        setPreparing(true);
        form.clearErrors('photo');
        try {
            const pendingPhoto = encodePhoto(video, video.videoWidth, video.videoHeight);
            closeCamera();
            const captured = await pendingPhoto;
            if (!captured) throw new Error('Não foi possível gerar a foto.');
            form.setData('photo', captured);
        } catch {
            form.setError('photo', 'Não conseguimos tirar a foto. Tente novamente ou escolha uma foto da galeria.');
            setCameraFailed(true);
        } finally {
            setPreparing(false);
        }
    }

    async function choosePhoto(input: HTMLInputElement) {
        if (!input.files?.[0]) return;
        setPreparing(true);
        form.clearErrors('photo');
        form.setData('photo', null);
        try {
            const file = await snapshotPhoto(input);
            if (!file) return;
            const prepared = await preparePhoto(file);
            if (prepared.size > 10 * 1024 * 1024) {
                form.setError('photo', 'Escolha uma foto de até 10 MB.');
                return;
            }
            form.setData('photo', prepared);
        } catch {
            form.setError('photo', 'Não conseguimos ler esta foto. Tire outra foto ou selecione uma imagem da galeria.');
        } finally {
            setPreparing(false);
        }
    }

    return (
        <section className="card">
            <h2>{mission.status === 'adjustment' ? 'Enviar de novo' : 'Enviar para revisão'}</h2>
            <form onSubmit={submit}>
                {mission.photo_mode !== 'disabled' && (
                    <fieldset className="options">
                        <legend>Foto {mission.photo_mode === 'required' ? 'obrigatória' : 'opcional'}</legend>
                        {supportsCamera && !cameraOpen && <button type="button" className="secondary" disabled={form.processing || preparing}
                            onClick={() => { void openCamera(); }}>Abrir câmera</button>}
                        <div hidden={!cameraOpen}>
                            <video ref={videoRef} className="evidence" muted playsInline aria-label="Prévia da câmera" />
                            {!cameraReady && <p role="status">Aguardando acesso à câmera…</p>}
                            <button type="button" disabled={!cameraReady || preparing} onClick={() => { void takePhoto(); }}>Tirar foto</button>
                            <button type="button" className="secondary" onClick={closeCamera}>Fechar câmera</button>
                        </div>
                        {cameraFailed && <p role="status">Não foi possível abrir a câmera no DAG. Use a câmera do celular ou a galeria.</p>}
                        {(!supportsCamera || cameraFailed) && !cameraOpen && <label>Câmera do celular
                            <input type="file" accept="image/*" capture="environment" disabled={form.processing || preparing}
                                onChange={event => { void choosePhoto(event.currentTarget); }} />
                        </label>}
                        <label>Escolher da galeria
                            <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" disabled={form.processing || preparing || cameraOpen}
                                onChange={event => { void choosePhoto(event.currentTarget); }} />
                        </label>
                        {preparing && <p role="status">Preparando foto…</p>}
                        {preview && !previewFailed && <img className="evidence" src={preview} alt="Prévia da foto escolhida"
                            onError={() => setPreviewFailed(true)} />}
                        {photo && previewFailed && <p>Foto anexada. A prévia deste formato aparece depois do envio.</p>}
                        {(photo || form.errors.photo) && <button type="button" className="secondary" disabled={form.processing || preparing}
                            onClick={() => { form.setData('photo', null); form.clearErrors('photo'); }}>Remover foto</button>}
                    </fieldset>
                )}
                <ShortText id="comment" label="Comentário opcional" value={form.data.comment} onChange={value => form.setData('comment', value)} />
                <p className="error" role="alert">{Object.values(form.errors).flat().join(' ')}</p>
                {form.progress && <p role="status">Enviando {form.progress.percentage}%</p>}
                <button type="submit" disabled={form.processing || preparing || cameraOpen || Boolean(form.errors.photo)}>Enviar para revisão</button>
            </form>
        </section>
    );
}

function GuardianActions({ mission, attempt, creditSettled, reviewQuery }: {
    mission: MissionDetails; attempt: Attempt | undefined; creditSettled: boolean; reviewQuery: string;
}) {
    const review = useForm({
        submission_id: attempt?.id ?? '', review_version: attempt?.review_version ?? 0,
        decision: String(attempt?.decision ?? ''), feedback: attempt?.feedback ?? '',
    });
    const reopen = useForm({ new_due_at: '', reason: '' });
    const url = `/guardian/revisoes/${mission.id}`;
    const query = reviewQuery ? `?${reviewQuery}` : '';

    return (
        <>
            {creditSettled && <p className="card">A revisão está bloqueada. Os pontos desta entrega já entraram em uma mesada fechada.</p>}
            {!creditSettled && attempt && (mission.status === 'in_review' || attempt.decision) && (
                <section className="card">
                    <h2>{attempt.decision ? 'Alterar revisão' : 'Revisar entrega'}</h2>
                    <form onSubmit={event => { event.preventDefault(); review.post(url + query); }}>
                        <label htmlFor="decision">Resultado</label>
                        <select id="decision" required value={review.data.decision} onChange={event => review.setData('decision', event.target.value)}>
                            <option value="" disabled>Escolha o resultado</option>
                            <option value="approved">Aprovar</option>
                            <option value="rejected">Reprovar</option>
                            <option value="adjustment">Pedir ajuste</option>
                        </select>
                        <ShortText id="feedback" label={review.data.decision === 'adjustment' ? 'O que precisa ajustar?' : 'Mensagem opcional'}
                            value={review.data.feedback} required={review.data.decision === 'adjustment'}
                            onChange={value => review.setData('feedback', value)} />
                        <p className="error" role="alert">{Object.values(review.errors).flat().join(' ')}</p>
                        <button disabled={review.processing} type="submit">Confirmar revisão</button>
                    </form>
                </section>
            )}
            {mission.status === 'expired' && (
                <section className="card">
                    <h2>Reabrir missão</h2>
                    <form onSubmit={event => { event.preventDefault(); reopen.post(`${url}/reabrir${query}`); }}>
                        <label htmlFor="new-due">Novo prazo no horário da família</label>
                        <input id="new-due" type="datetime-local" required value={reopen.data.new_due_at}
                            onChange={event => reopen.setData('new_due_at', event.target.value)} />
                        <ShortText id="reason" label="Motivo" value={reopen.data.reason} required onChange={value => reopen.setData('reason', value)} />
                        <p className="error" role="alert">{Object.values(reopen.errors).flat().join(' ')}</p>
                        <button disabled={reopen.processing} type="submit">Reabrir</button>
                    </form>
                </section>
            )}
        </>
    );
}

export default function Mission({ mission, attempts, reopening, requestId, isGuardian, creditSettled, reviewQuery }: {
    mission: MissionDetails; attempts: Attempt[]; reopening: string | null; requestId: string; isGuardian: boolean;
    creditSettled: boolean; reviewQuery: string;
}) {
    return (
        <main className="screen">
            <Head title={mission.title} />
            <header className="topbar">
                <h1>{mission.title}</h1>
                <Link className="button secondary" href={isGuardian
                    ? `/guardian/revisoes${reviewQuery ? `?${reviewQuery}` : ''}` : '/app/missoes'}>Voltar</Link>
            </header>
            <section className={`card mission-card status-${mission.status}`}>
                <div className="mission-card-labels"><MissionStatusBadge status={mission.status} /></div>
                <p>{mission.points} pontos</p>
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
                ? <GuardianActions mission={mission} attempt={attempts[0]} creditSettled={creditSettled} reviewQuery={reviewQuery}
                    key={attempts[0] ? `${attempts[0].id}:${attempts[0].review_version}` : mission.id} />
                : (mission.status === 'pending' || mission.status === 'adjustment') &&
                    <SubmissionForm mission={mission} requestId={requestId} key={attempts[0]?.id ?? mission.id} />}
        </main>
    );
}
