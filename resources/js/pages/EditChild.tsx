import { Head, Link } from '@inertiajs/react';
import ChildProfileForm, { type ChildProfile } from './ChildProfileForm';

export default function EditChild({ child }: { child: ChildProfile }) {
    return (
        <main className="screen">
            <Head title="Editar perfil infantil" />
            <header className="topbar">
                <h1>Editar perfil infantil</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            <section className="card">
                <ChildProfileForm child={child} />
            </section>
        </main>
    );
}
