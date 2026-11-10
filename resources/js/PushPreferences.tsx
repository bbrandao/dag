import { useEffect, useState } from 'react';

function supported() {
    return window.isSecureContext && 'Notification' in window
        && 'serviceWorker' in navigator && 'PushManager' in window;
}

async function sendPreference(path: string, method: 'POST' | 'DELETE', body: object) {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    const response = await fetch(`/app/push/${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '',
        },
        body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error('Não foi possível salvar a preferência.');

    return (await response.json()) as { enabled: boolean };
}

function decodeKey(key: string) {
    const base64 = key.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(key.length / 4) * 4, '=');

    return Uint8Array.from(atob(base64), character => character.charCodeAt(0));
}

export default function PushPreferences({ publicKey, guardian }: { publicKey: string; guardian: boolean }) {
    const [enabled, setEnabled] = useState(false);
    const [busy, setBusy] = useState(supported);
    const [message, setMessage] = useState('');
    const available = supported();

    useEffect(() => {
        if (!available) return;
        let current = true;
        void (async () => {
            try {
                const registration = await navigator.serviceWorker.getRegistration();
                const subscription = await registration?.pushManager.getSubscription();
                if (subscription && current) {
                    const result = await sendPreference('status', 'POST', { endpoint: subscription.endpoint });
                    if (current) setEnabled(result.enabled);
                }
            } catch {
                if (current) setMessage('Não foi possível consultar as notificações deste aparelho.');
            } finally {
                if (current) setBusy(false);
            }
        })();

        return () => { current = false; };
    }, [available, publicKey]);

    async function toggle() {
        setBusy(true);
        setMessage('');
        try {
            if (!enabled && await Notification.requestPermission() !== 'granted') {
                setMessage('Permita as notificações do DAG nas configurações do aparelho para ativar.');
                return;
            }

            const registration = await navigator.serviceWorker.register('/sw.js');
            if (!registration.active) await navigator.serviceWorker.ready;
            let subscription = await registration.pushManager.getSubscription();
            if (enabled) {
                if (subscription) {
                    await sendPreference('assinatura', 'DELETE', { endpoint: subscription.endpoint });
                    await subscription.unsubscribe();
                }
                setEnabled(false);
                setMessage('Notificações desativadas neste aparelho.');
                return;
            }

            const key = decodeKey(publicKey);
            const currentKey = subscription?.options.applicationServerKey;
            if (subscription && (!currentKey || new Uint8Array(currentKey).toString() !== key.toString())) {
                await subscription.unsubscribe();
                subscription = null;
            }
            subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
            await sendPreference('assinatura', 'POST', subscription.toJSON());
            setEnabled(true);
            setMessage('Notificações ativadas neste aparelho.');
        } catch {
            setMessage('Não foi possível alterar as notificações. Tente novamente.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="card">
            <h2>Notificações do celular</h2>
            <p>{guardian ? 'Receba um aviso quando chegar uma entrega para revisão.'
                : 'Receba um lembrete quando faltarem 15 minutos para entregar uma tarefa.'}</p>
            {available
                ? <button type="button" disabled={busy} onClick={() => void toggle()}>
                    {enabled ? 'Desativar neste aparelho' : 'Ativar neste aparelho'}
                </button>
                : <p>Abra o DAG pelo ícone da tela inicial em um aparelho compatível. No iPhone, é necessário iOS 16.4 ou posterior.</p>}
            {message && <p role="status">{message}</p>}
        </section>
    );
}
