import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

interface Notice {
    id: string;
    title: string;
    message: string;
    href: string;
    unread: boolean;
    occurred_at: string;
}

export default function Inbox({ events }: { events: Notice[] }) {
    const form = useForm({ ids: [] as string[] });
    const unread = events.filter(event => event.unread).map(event => event.id);

    useEffect(() => {
        const refresh = () => {
            if (!document.hidden && navigator.onLine) router.reload({ only: ['events'] });
        };
        const timer = window.setInterval(refresh, 30000);
        document.addEventListener('visibilitychange', refresh);
        window.addEventListener('online', refresh);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
            window.removeEventListener('online', refresh);
        };
    }, []);

    return (
        <main className="screen">
            <Head title="Avisos" />
            <header className="topbar">
                <h1>Avisos</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            {unread.length > 0 && (
                <p><button type="button" className="secondary" disabled={form.processing} onClick={() => {
                    form.transform(() => ({ ids: unread }));
                    form.patch('/app/avisos/lidos', { preserveScroll: true });
                }}>Marcar avisos exibidos como lidos</button></p>
            )}
            {events.length === 0 && <p className="card">Nenhum aviso por enquanto.</p>}
            {events.map(event => (
                <article className="card" key={event.id}>
                    {event.unread && <p className="tag">Novo</p>}
                    <h2>{event.title}</h2>
                    <p>{event.message} · {event.occurred_at}</p>
                    <Link className="button secondary" href={event.href}>Abrir missão</Link>
                </article>
            ))}
        </main>
    );
}
