import { useEffect, useState } from 'react';
import { requestPreference } from './requestPreference';

function supported() {
    return window.isSecureContext && 'Notification' in window
        && 'serviceWorker' in navigator && 'PushManager' in window;
}

function decodeKey(key: string) {
    const base64 = key.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(key.length / 4) * 4, '=');

    return Uint8Array.from(atob(base64), character => character.charCodeAt(0));
}

export function usePushPreferences(publicKey: string | null) {
    const [enabled, setEnabled] = useState(false);
    const [busy, setBusy] = useState(() => Boolean(publicKey) && supported());
    const [message, setMessage] = useState('');
    const available = Boolean(publicKey) && supported();

    useEffect(() => {
        if (!available) return;
        let current = true;
        setBusy(true);
        void (async () => {
            try {
                const registration = await navigator.serviceWorker.getRegistration();
                const subscription = await registration?.pushManager.getSubscription();
                if (subscription && current) {
                    const active = await requestPreference('/app/push/status', 'POST', { endpoint: subscription.endpoint });
                    if (current) setEnabled(active);
                } else if (current) {
                    setEnabled(false);
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
        if (!publicKey || !available || busy) return;
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
                    await requestPreference('/app/push/assinatura', 'DELETE', { endpoint: subscription.endpoint });
                    await subscription.unsubscribe().catch(() => {});
                }
                setEnabled(false);
                return;
            }

            const key = decodeKey(publicKey);
            const currentKey = subscription?.options.applicationServerKey;
            if (subscription && (!currentKey || new Uint8Array(currentKey).toString() !== key.toString())) {
                await subscription.unsubscribe();
                subscription = null;
            }
            subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
            setEnabled(await requestPreference('/app/push/assinatura', 'POST', subscription.toJSON()));
        } catch {
            setMessage('Não foi possível alterar as notificações. Tente novamente.');
        } finally {
            setBusy(false);
        }
    }

    return { available, busy, enabled: available && enabled, message, toggle };
}
