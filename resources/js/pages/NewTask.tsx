import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { Button } from '../components/ui/button';
import { Toast, useToast } from '../components/ui/toast';
import TaskForm, { type EditableTask, type TaskFormData } from './TaskForm';

export default function NewTask({ task, ...props }: TaskFormData & { task?: EditableTask }) {
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const { toast, setToast } = useToast();
    const title = task ? 'Editar Tarefa' : 'Nova Tarefa';
    const success = task ? 'Tarefa atualizada com sucesso.' : 'Tarefa cadastrada com sucesso.';
    const failure = task ? 'Não foi possível atualizar a tarefa.' : 'Não foi possível cadastrar a tarefa.';

    return <main className="screen">
        <Head title={title} />
        <header className="topbar flex-wrap">
            <h1>{title}</h1>
            <Button asChild><Link href="/guardian/tasks" aria-disabled={busy || undefined}
                tabIndex={busy ? -1 : undefined} className="aria-disabled:opacity-60"
                onClick={event => { if (busy) event.preventDefault(); }}>
                <ArrowLeft size={17} aria-hidden="true" /> Voltar
            </Link></Button>
        </header>
        <section className="card" aria-label={task ? 'Edição de tarefa' : 'Cadastro de tarefa'}>
            {saved ? <p>{success}</p> : <TaskForm {...props} task={task} onBusyChange={setBusy}
                onSaved={() => setSaved(true)}
                onFailure={() => setToast({ success: false, text: `${failure} Confira os dados e tente novamente.` })} />}
        </section>
        <Toast message={saved ? { success: true, text: success } : toast}
            onConfirm={saved ? () => router.visit('/guardian/tasks') : undefined} />
    </main>;
}
