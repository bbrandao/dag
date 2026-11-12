import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Avatar, AvatarFallback } from './components/ui/avatar';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from './components/ui/popover';
import { openNotice } from './notices';
import InstallDag from './InstallDag';
import { useNoticeSummary } from './useNoticeSummary';

function initials(name: string) {
    const parts = name.trim().split(/\s+/);
    const first = Array.from(parts[0])[0] ?? '';
    const last = parts.length > 1 ? Array.from(parts[parts.length - 1])[0] ?? '' : '';
    return `${first}${last}`.toLocaleUpperCase('pt-BR');
}

function Navigation({ name }: { name: string }) {
    const [desktop, setDesktop] = useState(() => window.matchMedia('(min-width: 48rem)').matches);
    const [panel, setPanel] = useState<'notices' | 'profile' | null>(null);
    const { summary, failed, refresh } = useNoticeSummary();
    const { url } = usePage();
    const side = desktop ? 'bottom' : 'top';
    const unread = summary?.unread ?? 0;
    const Bar = desktop ? 'header' : 'footer';

    useEffect(() => {
        const query = window.matchMedia('(min-width: 48rem)');
        const update = () => setDesktop(query.matches);
        query.addEventListener('change', update);
        return () => query.removeEventListener('change', update);
    }, []);

    useEffect(() => { setPanel(null); }, [url]);

    return <Bar className="fixed inset-x-0 bottom-0 z-40 border-t border-solid border-dag-line bg-white pb-[env(safe-area-inset-bottom)] md:sticky md:top-0 md:bottom-auto md:border-t-0 md:border-b md:pb-0">
        <nav aria-label="Navegação principal" className="mx-auto flex min-h-16 max-w-6xl items-center justify-between gap-3 px-4">
            <Link href="/app" className="inline-flex min-h-11 min-w-0 items-center gap-2 rounded-lg font-extrabold text-dag-ink no-underline">
                <img src="/icons/dag-192.png" alt="" width="34" height="34" className="shrink-0 rounded-xl" />
                <span>Do And Gain</span>
            </Link>
            <div className="flex shrink-0 items-center gap-2">
                <Popover open={panel === 'notices'} onOpenChange={open => {
                    setPanel(current => open ? 'notices' : current === 'notices' ? null : current);
                    if (open) refresh();
                }}>
                    <PopoverTrigger asChild>
                        <Button type="button" aria-label={unread ? `Avisos, ${unread} não lidos` : 'Avisos'} className="relative size-11 cursor-pointer px-0">
                            <Bell aria-hidden="true" size={21} />
                            {unread > 0 && <Badge aria-hidden="true" className="absolute -top-1 -right-1 border-2 border-solid border-white">
                                {unread}
                            </Badge>}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent side={side} aria-label="Avisos não lidos">
                        <h2 className="text-base font-extrabold">Avisos</h2>
                        {failed && <p role="status" className="text-sm text-dag-muted">Não foi possível atualizar os avisos. <button type="button"
                            data-slot="button" className="cursor-pointer border-0 bg-transparent p-0 text-dag-purple underline" onClick={refresh}>Tentar novamente</button></p>}
                        {!failed && !summary && <p role="status" className="text-sm text-dag-muted">Carregando avisos…</p>}
                        {summary && summary.events.length === 0 && <p className="text-sm text-dag-muted">Você está em dia com os avisos.</p>}
                        {summary && summary.events.length > 0 && <ul className="m-0 grid list-none gap-1 p-0">
                            {summary.events.map(notice => <li key={notice.id}>
                                <a href={notice.href} onClick={event => { void openNotice(event, notice, () => setPanel(null)); }}
                                    className="block rounded-xl px-3 py-2 text-sm wrap-anywhere text-dag-ink no-underline hover:bg-dag-lilac">
                                    <strong className="block">{notice.title}</strong>
                                    <span className="block text-dag-muted">{notice.message} · {notice.occurred_at}</span>
                                </a>
                            </li>)}
                        </ul>}
                        <div className="mt-2 flex justify-center border-0 border-t border-solid border-dag-line pt-2">
                            <Link href="/app/avisos?filter=unread" onClick={() => setPanel(null)}
                                className="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-bold text-dag-purple">Ver todos os avisos</Link>
                        </div>
                    </PopoverContent>
                </Popover>
                <Popover open={panel === 'profile'} onOpenChange={open => {
                    setPanel(current => open ? 'profile' : current === 'profile' ? null : current);
                }}>
                    <PopoverTrigger asChild>
                        <Button type="button" aria-label={`Perfil de ${name}`} className="size-11 cursor-pointer bg-white px-0">
                            <Avatar aria-hidden="true"><AvatarFallback>{initials(name)}</AvatarFallback></Avatar>
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent side={side} aria-label="Perfil" className="w-52">
                        <div className="text-sm font-bold wrap-anywhere">{name}</div>
                    </PopoverContent>
                </Popover>
            </div>
        </nav>
    </Bar>;
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const { component, props } = usePage<{ viewer?: { id: string; name: string } | null }>();
    const viewer = props.viewer;

    return <div className={viewer ? 'dag-authenticated' : undefined}>
        {viewer && <Navigation key={viewer.id} name={viewer.name} />}
        <InstallDag visible={Boolean(viewer) && component === 'Dashboard'} />
        {children}
    </div>;
}
