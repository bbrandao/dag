import { Head, Link, useForm } from '@inertiajs/react';
import { openNotice, type Notice } from '../notices';

export default function Inbox({ events, pagination, unreadOnly }: {
    events: Notice[];
    pagination: { previous: string | null; next: string | null };
    unreadOnly: boolean;
}) {
    const form = useForm({ ids: [] as string[], filter: null as 'unread' | null });
    const unread = events.filter(event => event.unread).map(event => event.id);

    return (
        <main className="screen">
            <Head title="Avisos" />
            <header className="topbar">
                <h1>Avisos</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            <nav className="filters" aria-label="Filtrar avisos">
                <Link href="/app/avisos" className={`button${unreadOnly ? ' secondary' : ''}`}
                    aria-current={unreadOnly ? undefined : 'page'}>Recentes</Link>
                <Link href="/app/avisos?filter=unread" className={`button${unreadOnly ? '' : ' secondary'}`}
                    aria-current={unreadOnly ? 'page' : undefined}>Não lidos</Link>
            </nav>
            {unread.length > 0 && (
                <p><button type="button" className="secondary" disabled={form.processing} onClick={() => {
                    form.transform(() => ({ ids: unread, filter: unreadOnly ? 'unread' : null }));
                    form.patch('/app/avisos/lidos', { preserveScroll: true });
                }}>Marcar avisos exibidos como lidos</button></p>
            )}
            {events.length === 0 && <p className="card">{unreadOnly ? 'Nenhum aviso não lido nesta página.' : 'Nenhum aviso por enquanto.'}</p>}
            {events.map(event => (
                <article className="card" key={event.id}>
                    {event.unread && <p className="tag">Novo</p>}
                    <h2>{event.title}</h2>
                    <p>{event.message} · {event.occurred_at}</p>
                    <a className="button secondary" href={event.href}
                        onClick={click => { void openNotice(click, event); }}>Abrir missão</a>
                </article>
            ))}
            {(pagination.previous || pagination.next) && <nav className="filters" aria-label="Páginas de avisos">
                {pagination.previous && <Link className="button secondary" href={pagination.previous}>Anteriores</Link>}
                {pagination.next && <Link className="button secondary" href={pagination.next}>Próximos</Link>}
            </nav>}
        </main>
    );
}
