import { useEffect, useState } from 'react';

interface InstallPrompt extends Event {
    prompt(): Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const installationNavigator = navigator as Navigator & {
    getInstalledRelatedApps?: () => Promise<{ platform: string }[]>;
};

function installed() {
    return window.matchMedia('(display-mode: standalone)').matches
        || Boolean((navigator as Navigator & { standalone?: boolean }).standalone);
}

export function useDagInstall() {
    const [isInstalled, setInstalled] = useState(installed);
    const [installPrompt, setInstallPrompt] = useState<InstallPrompt | null>(null);
    const [help, setHelp] = useState(false);
    const [busy, setBusy] = useState(false);
    const [checking, setChecking] = useState(() => !installed() && Boolean(installationNavigator.getInstalledRelatedApps));

    useEffect(() => {
        const capture = (event: Event) => {
            event.preventDefault();
            setInstallPrompt(event as InstallPrompt);
        };
        const markInstalled = () => { setInstalled(true); setHelp(false); };
        const mode = window.matchMedia('(display-mode: standalone)');
        const checkMode = () => { if (installed()) markInstalled(); };
        let current = true;
        if (!installed() && installationNavigator.getInstalledRelatedApps) {
            void installationNavigator.getInstalledRelatedApps().then(apps => {
                if (current && apps.some(app => app.platform === 'webapp')) markInstalled();
            }).catch(() => {}).finally(() => { if (current) setChecking(false); });
        }
        window.addEventListener('beforeinstallprompt', capture);
        window.addEventListener('appinstalled', markInstalled);
        mode.addEventListener('change', checkMode);

        return () => {
            current = false;
            window.removeEventListener('beforeinstallprompt', capture);
            window.removeEventListener('appinstalled', markInstalled);
            mode.removeEventListener('change', checkMode);
        };
    }, []);

    async function install() {
        if (busy || checking || isInstalled) return;
        if (!installPrompt) {
            setHelp(value => !value);
            return;
        }
        setBusy(true);
        setHelp(false);
        try {
            await installPrompt.prompt();
            if ((await installPrompt.userChoice).outcome === 'accepted') setInstalled(true);
        } catch {
            setHelp(true);
        } finally {
            setInstallPrompt(null);
            setBusy(false);
        }
    }

    return { isInstalled, busy: busy || checking, help, install };
}
