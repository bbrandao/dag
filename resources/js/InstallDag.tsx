import { useEffect, useState } from 'react';

interface InstallPrompt extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

function installed() {
    return window.matchMedia('(display-mode: standalone)').matches
        || Boolean((navigator as Navigator & { standalone?: boolean }).standalone);
}

export default function InstallDag({ visible }: { visible: boolean }) {
    const [isInstalled, setInstalled] = useState(installed);
    const [installPrompt, setInstallPrompt] = useState<InstallPrompt | null>(null);
    const [help, setHelp] = useState(false);

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

    if (!visible || isInstalled) return null;

    return <aside aria-label="Instalação do DAG" className="mx-auto max-w-160 px-4 pt-3 text-right">
        <button type="button" className="secondary" onClick={() => void install()}>Instalar DAG</button>
        {help && <p>No iPhone, abra no Safari, toque em Compartilhar e em Adicionar à Tela de Início. Em outros aparelhos, procure Instalar ou Adicionar à tela inicial no menu do navegador.</p>}
    </aside>;
}
