import { router, useForm } from '@inertiajs/react';
import { Check, CircleAlert, LogOut } from 'lucide-react';
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Avatar, AvatarFallback } from './components/ui/avatar';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from './components/ui/popover';
import { Switch } from './components/ui/switch';
import { requestPreference } from './requestPreference';
import { usePushPreferences } from './usePushPreferences';

export interface Viewer {
    id: string;
    name: string;
    role: 'guardian' | 'child';
    relationship: string | null;
    whatsappNotices: boolean;
    pushPublicKey: string | null;
}

function initials(name: string) {
    const parts = name.trim().split(/\s+/);
    const first = Array.from(parts[0])[0] ?? '';
    const last = parts.length > 1 ? Array.from(parts[parts.length - 1])[0] ?? '' : '';
    return `${first}${last}`.toLocaleUpperCase('pt-BR');
}

export function ProfileMenu({ viewer, side, open, onOpenChange }: {
    viewer: Viewer;
    side: 'top' | 'bottom';
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const notifications = usePushPreferences(viewer.pushPublicKey);
    const logout = useForm({});
    const [saving, setSaving] = useState(false);
    const [toast, setToast] = useState<{ success: boolean; text: string } | null>(null);
    const role = viewer.role === 'child' ? 'Criança' : viewer.relationship || 'Responsável';

    useEffect(() => {
        if (!toast) return;
        const timeout = window.setTimeout(() => setToast(null), 3500);
        return () => window.clearTimeout(timeout);
    }, [toast]);

    async function toggleWhatsApp() {
        if (saving) return;
        setSaving(true);
        setToast(null);
        try {
            const enabled = await requestPreference('/app/avisos', 'PATCH', { enabled: !viewer.whatsappNotices });
            router.replaceProp('viewer.whatsappNotices', enabled);
            setToast({ success: true, text: enabled ? 'Avisos no WhatsApp ativados.' : 'Avisos no WhatsApp desativados.' });
        } catch {
            setToast({ success: false, text: 'Não foi possível alterar os avisos no WhatsApp.' });
        } finally {
            setSaving(false);
        }
    }

    const notificationHint = notifications.message || (!viewer.pushPublicKey
        ? 'Notificações indisponíveis.'
        : !notifications.available ? 'Abra o DAG pela tela inicial em um aparelho compatível.' : 'Neste aparelho');

    return <>
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>
                <Button type="button" aria-label={`Perfil de ${viewer.name}`} className="size-11 cursor-pointer bg-white px-0">
                    <Avatar aria-hidden="true"><AvatarFallback>{initials(viewer.name)}</AvatarFallback></Avatar>
                </Button>
            </PopoverTrigger>
            <PopoverContent side={side} aria-label="Perfil" className="w-68">
                <div className="grid justify-items-start gap-1.5 px-2 py-1">
                    <div className="text-sm font-bold wrap-anywhere">{viewer.name}</div>
                    <Badge className="bg-dag-lilac px-2 py-1 capitalize">{role}</Badge>
                </div>
                <hr className="my-2 border-0 border-t border-solid border-dag-line" />
                <Switch checked={notifications.enabled} disabled={!notifications.available || notifications.busy || logout.processing}
                    aria-describedby="dag-push-hint" onClick={() => { void notifications.toggle(); }}>
                    Notificações
                </Switch>
                <div id="dag-push-hint" role={notifications.message ? 'status' : undefined} className="px-2 pb-1 text-xs text-dag-muted">
                    {notificationHint}
                </div>
                <Switch checked={viewer.whatsappNotices} disabled={saving || logout.processing} aria-busy={saving}
                    onClick={() => { void toggleWhatsApp(); }}>Avisos no WhatsApp</Switch>
                <hr className="my-2 border-0 border-t border-solid border-dag-line" />
                <Button type="button" disabled={logout.processing || saving} className="w-full cursor-pointer justify-start bg-white text-red-800 hover:bg-red-50"
                    onClick={() => logout.post('/logout')}>
                    <LogOut aria-hidden="true" size={17} /> Sair
                </Button>
            </PopoverContent>
        </Popover>
        {toast && createPortal(
            <div className="pointer-events-none fixed inset-0 z-60 grid place-items-center px-5">
                <div role={toast.success ? 'status' : 'alert'} className={`flex max-w-sm items-center gap-3 rounded-xl border border-solid px-4 py-3 text-sm font-bold shadow-xl ${
                    toast.success ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900'
                }`}>
                    {toast.success ? <Check aria-hidden="true" className="size-5 shrink-0" /> : <CircleAlert aria-hidden="true" className="size-5 shrink-0" />}
                    {toast.text}
                </div>
            </div>, document.body,
        )}
    </>;
}
