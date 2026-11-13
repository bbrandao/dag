import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { Button } from '../components/ui/button';
import { Toast, useToast } from '../components/ui/toast';
import TaskForm, { type TaskFormData } from './TaskForm';

export default function NewTask(props: TaskFormData) {
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const { toast, setToast } = useToast();

    return <main className="screen">
        <Head title="Nova Tarefa" />
        <header className="topbar flex-wrap">
            <h1>Nova Tarefa</h1>
            <Button asChild><Link href="/guardian/tasks" aria-disabled={busy || undefined}
                tabIndex={busy ? -1 : undefined} className="aria-disabled:opacity-60"
                onClick={event => { if (busy) event.preventDefault(); }}>
                <ArrowLeft size={17} aria-hidden="true" /> Voltar
            </Link></Button>
        </header>
        <section className="card" aria-label="Cadastro de tarefa">
            {saved ? <p>Tarefa cadastrada.</p> : <TaskForm {...props} onBusyChange={setBusy}
                onSaved={() => setSaved(true)}
                onFailure={() => setToast({ success: false, text: 'Não foi possível cadastrar a tarefa. Confira os dados e tente novamente.' })} />}
        </section>
        <Toast message={saved ? { success: true, text: 'Tarefa cadastrada com sucesso.' } : toast}
            onConfirm={saved ? () => router.visit('/guardian/tasks') : undefined} />
    </main>;
}
