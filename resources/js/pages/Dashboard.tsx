import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

interface DashboardProps {
    name: string;
    role: 'guardian' | 'child';
    family: string;
    children: { id: string; name: string }[];
}

export default function Dashboard({ name, role, family, children }: DashboardProps) {
    const form = useForm({ name: '', birth_date: '', phone: '+55', authorized: false });
    const isGuardian = role === 'guardian';

    function registerChild(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/guardian/children', { onSuccess: () => form.reset() });
    }

    return (
        <main className="screen">
            <Head title={isGuardian ? 'Família' : 'Meu espaço'} />
            <header className="topbar">
                <p className="brand"><span aria-hidden="true">✓</span> Do And Gain</p>
                <button type="button" className="secondary" onClick={() => router.post('/logout')}>Sair</button>
            </header>
            <section className="card">
                <p className="tag">{isGuardian ? 'Responsável' : 'Meu espaço'}</p>
                <h1>Olá, {name}!</h1>
                <p>{isGuardian ? family : 'Você entrou no seu perfil.'}</p>
                <Link className="button" href={isGuardian ? '/guardian/tasks' : '/app/missoes'}>
                    {isGuardian ? 'Gerenciar tarefas' : 'Ver minhas missões'}
                </Link>
            </section>
            {isGuardian && (
                <section className="card" aria-labelledby="children-title">
                    <h2 id="children-title">Filhos vinculados</h2>
                    {children.length ? (
                        <ul>{children.map(child => <li key={child.id}>{child.name}</li>)}</ul>
                    ) : <p>Cadastre o primeiro perfil da família.</p>}
                    <form onSubmit={registerChild}>
                        <label htmlFor="name">Nome</label>
                        <input id="name" required maxLength={120} value={form.data.name}
                            aria-describedby="name-error" aria-invalid={Boolean(form.errors.name)}
                            onChange={event => form.setData('name', event.target.value)} />
                        <p id="name-error" className="error" role="alert">{form.errors.name}</p>
                        <label htmlFor="birth-date">Data de nascimento</label>
                        <input id="birth-date" type="date" required value={form.data.birth_date}
                            aria-describedby="date-error" aria-invalid={Boolean(form.errors.birth_date)}
                            onChange={event => form.setData('birth_date', event.target.value)} />
                        <p id="date-error" className="error" role="alert">{form.errors.birth_date}</p>
                        <label htmlFor="child-phone">WhatsApp próprio com código do país</label>
                        <input id="child-phone" type="tel" required maxLength={16} value={form.data.phone}
                            aria-describedby="phone-error" aria-invalid={Boolean(form.errors.phone)}
                            onChange={event => form.setData('phone', event.target.value)} />
                        <p id="phone-error" className="error" role="alert">{form.errors.phone}</p>
                        <label className="check">
                            <input type="checkbox" required checked={form.data.authorized}
                                onChange={event => form.setData('authorized', event.target.checked)} />
                            Confirmo que tenho autorização para cadastrar este perfil.
                        </label>
                        <p className="error" role="alert">{form.errors.authorized}</p>
                        <button type="submit" disabled={form.processing}>Cadastrar perfil</button>
                        {form.recentlySuccessful && <p role="status">Perfil cadastrado.</p>}
                    </form>
                </section>
            )}
        </main>
    );
}
