import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface InstallPrompt extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

function installed() {
    return window.matchMedia('(display-mode: standalone)').matches
        || Boolean((navigator as Navigator & { standalone?: boolean }).standalone);
}

export default function PwaBar({ initiallyAuthenticated }: { initiallyAuthenticated: boolean }) {
    const [authenticated, setAuthenticated] = useState(initiallyAuthenticated);
    const [navigation, setNavigation] = useState(0);
    const [unread, setUnread] = useState<number | null>(null);
    const [isInstalled, setInstalled] = useState(installed);
    const [installPrompt, setInstallPrompt] = useState<InstallPrompt | null>(null);
    const [help, setHelp] = useState(false);

    useEffect(() => router.on('navigate', event => {
        setAuthenticated(event.detail.page.props.authenticated === true);
        setNavigation(value => value + 1);
    }), []);

    useEffect(() => {
        if (!authenticated) {
            setUnread(null);
            return;
        }
        const controller = new AbortController();
        let running = false;
        let delay = 30000;
        let retryAfter = 0;

        async function refresh(force = false) {
            if (running || document.hidden || !navigator.onLine || (!force && Date.now() < retryAfter)) return;
            running = true;
            try {
                const response = await fetch('/app/avisos/contagem', {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (response.status === 401) {
                    setUnread(null);
                    return;
                }
                if (!response.ok) throw new Error('Não foi possível carregar os avisos.');
                const data: { unread: number } = await response.json();
                if (!controller.signal.aborted) setUnread(data.unread);
                delay = 30000;
                retryAfter = 0;
            } catch {
                retryAfter = Date.now() + delay;
                delay = Math.min(delay * 2, 120000);
            } finally {
                running = false;
            }
        }

        const resume = () => void refresh(true);
        const timer = window.setInterval(() => void refresh(), 30000);
        document.addEventListener('visibilitychange', resume);
        window.addEventListener('online', resume);
        resume();

        return () => {
            controller.abort();
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', resume);
            window.removeEventListener('online', resume);
        };
    }, [authenticated, navigation]);

    useEffect(() => {
        const capture = (event: Event) => {
            event.preventDefault();
            setInstallPrompt(event as InstallPrompt);
        };
        const markInstalled = () => { setInstalled(true); setHelp(false); };
        window.addEventListener('beforeinstallprompt', capture);
        window.addEventListener('appinstalled', markInstalled);

        return () => {
            window.removeEventListener('beforeinstallprompt', capture);
            window.removeEventListener('appinstalled', markInstalled);
        };
    }, []);

    async function install() {
        if (!installPrompt) {
            setHelp(value => !value);
            return;
        }
        try {
            await installPrompt.prompt();
            if ((await installPrompt.userChoice).outcome === 'accepted') setInstalled(true);
        } catch {
            setHelp(true);
        } finally {
            setInstallPrompt(null);
        }
    }

    if (!authenticated && isInstalled) return null;

    return (
        <aside className="pwa-bar" aria-label="Avisos e instalação">
            {authenticated && <a href="/app/avisos">Avisos <span aria-live="polite">{unread ? `(${unread} novos)` : ''}</span></a>}
            {!isInstalled && <button type="button" className="secondary" onClick={() => void install()}>Instalar DAG</button>}
            {help && <p>No iPhone, abra no Safari, toque em Compartilhar e em Adicionar à Tela de Início. Em outros aparelhos, procure Instalar ou Adicionar à tela inicial no menu do navegador.</p>}
        </aside>
    );
}
