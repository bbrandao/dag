import { Head, Link, router, useForm } from '@inertiajs/react';
import ChildProfileForm from './ChildProfileForm';

interface DashboardProps {
    name: string;
    role: 'guardian' | 'child';
    family: string;
    children: { id: string; name: string }[];
    whatsappNotices: boolean;
    earnedPoints: number;
}

export default function Dashboard({ name, role, family, children, whatsappNotices, earnedPoints }: DashboardProps) {
    const notices = useForm({ enabled: whatsappNotices });
    const isGuardian = role === 'guardian';

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
                {!isGuardian && <p className="tag reward">{earnedPoints} pontos conquistados</p>}
                <div className="filters">
                    <Link className="button" href={isGuardian ? '/guardian/tasks' : '/app/missoes'}>
                        {isGuardian ? 'Gerenciar tarefas' : 'Ver minhas missões'}
                    </Link>
                    {isGuardian && <Link className="button secondary" href="/guardian/revisoes">Revisar missões</Link>}
                </div>
            </section>
            <section className="card">
                <h2>Avisos</h2>
                <form onSubmit={event => { event.preventDefault(); notices.patch('/app/avisos'); }}>
                    <label className="check">
                        <input type="checkbox" checked={notices.data.enabled}
                            onChange={event => notices.setData('enabled', event.target.checked)} />
                        {isGuardian ? 'Avisar pelo WhatsApp quando houver uma entrega' : 'Receber o resultado da revisão pelo WhatsApp'}
                    </label>
                    <button type="submit" disabled={notices.processing || notices.data.enabled === whatsappNotices}>Salvar preferência</button>
                    {notices.recentlySuccessful && <p role="status">Preferência salva.</p>}
                </form>
            </section>
            {isGuardian && (
                <section className="card" aria-labelledby="children-title">
                    <h2 id="children-title">Filhos vinculados</h2>
                    {children.length ? (
                        <ul>{children.map(child => (
                            <li key={child.id}>
                                {child.name} <Link href={`/guardian/children/${child.id}/editar`} aria-label={`Editar perfil de ${child.name}`}>Editar</Link>
                            </li>
                        ))}</ul>
                    ) : <p>Cadastre o primeiro perfil da família.</p>}
                    <ChildProfileForm />
                </section>
            )}
        </main>
    );
}
