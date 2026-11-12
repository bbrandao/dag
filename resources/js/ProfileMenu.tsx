import { Link, router, useForm } from '@inertiajs/react';
import { Download, LogOut, Users } from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback } from './components/ui/avatar';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from './components/ui/popover';
import { Switch } from './components/ui/switch';
import { Toast, useToast } from './components/ui/toast';
import { requestPreference } from './requestPreference';
import type { useDagInstall } from './useDagInstall';
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

export function ProfileMenu({ viewer, installation, side, open, onOpenChange }: {
    viewer: Viewer;
    installation: ReturnType<typeof useDagInstall>;
    side: 'top' | 'bottom';
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const notifications = usePushPreferences(viewer.pushPublicKey);
    const logout = useForm({});
    const [saving, setSaving] = useState(false);
    const { toast, setToast } = useToast();
    const role = viewer.role === 'child' ? 'Criança' : viewer.relationship || 'Responsável';

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
                {viewer.role === 'guardian' && <>
                    <Button asChild className="w-full justify-start bg-white hover:bg-dag-lilac">
                        <Link href="/guardian/children" onClick={() => onOpenChange(false)}>
                            <Users aria-hidden="true" size={17} /> Filhos
                        </Link>
                    </Button>
                    <hr className="my-2 border-0 border-t border-solid border-dag-line" />
                </>}
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
                <Button type="button" disabled={installation.isInstalled || installation.busy}
                    className="w-full cursor-pointer justify-start bg-white hover:bg-dag-lilac disabled:cursor-default"
                    aria-describedby={installation.isInstalled || installation.help ? 'dag-install-hint' : undefined}
                    onClick={() => { void installation.install(); }}>
                    <Download aria-hidden="true" size={17} /> Instalar DAG
                </Button>
                {installation.isInstalled && <div id="dag-install-hint" className="px-3 pb-1 text-xs text-dag-muted">Já instalado</div>}
                {installation.help && <div id="dag-install-hint" role="status" className="px-3 pb-1 text-xs text-dag-muted">
                    No iPhone, abra no Safari e toque em Compartilhar e depois em Adicionar à Tela de Início. Nos outros aparelhos, procure Instalar ou Adicionar à tela inicial no menu do navegador.
                </div>}
                <hr className="my-2 border-0 border-t border-solid border-dag-line" />
                <Button type="button" disabled={logout.processing || saving} className="w-full cursor-pointer justify-start bg-white text-red-800 hover:bg-red-50"
                    onClick={() => logout.post('/logout')}>
                    <LogOut aria-hidden="true" size={17} /> Sair
                </Button>
            </PopoverContent>
        </Popover>
        <Toast message={toast} />
    </>;
}
