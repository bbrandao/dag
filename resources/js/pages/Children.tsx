import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '../components/ui/button';
import { Toast, useToast } from '../components/ui/toast';
import { formatDate } from '../tasks';
import ChildProfileForm, { type ChildProfile } from './ChildProfileForm';

const nameOrder = new Intl.Collator('pt-BR', { sensitivity: 'base' });

export default function Children({ children }: { children: (ChildProfile & { age: number })[] }) {
    const dialog = useRef<HTMLDialogElement>(null);
    const [open, setOpen] = useState(false);
    const { toast, setToast } = useToast();
    const sorted = [...children].sort((left, right) => nameOrder.compare(left.name, right.name));

    function createChild() {
        setToast(null);
        setOpen(true);
        dialog.current?.showModal();
    }

    function close() {
        dialog.current?.close();
        setOpen(false);
    }

    return <main className="screen">
        <Head title="Filhos" />
        <header className="topbar flex-wrap">
            <h1>Filhos</h1>
            <div className="flex flex-wrap gap-2">
                <Button asChild><Link href="/app"><ArrowLeft size={17} aria-hidden="true" /> Voltar</Link></Button>
                <Button type="button" className="cursor-pointer bg-dag-purple text-white hover:bg-[#452497]" onClick={createChild}>
                    <Plus size={17} aria-hidden="true" /> Novo
                </Button>
            </div>
        </header>
        {sorted.length === 0 && <p className="card">Nenhum filho cadastrado. Toque em Novo para cadastrar.</p>}
        {sorted.map(child => (
            <article className="card" key={child.id}>
                <h2>{child.name}</h2>
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm wrap-anywhere">
                    <dt className="text-dag-muted">Idade</dt><dd className="m-0">{child.age} {child.age === 1 ? 'ano' : 'anos'}</dd>
                    <dt className="text-dag-muted">Data de nascimento</dt><dd className="m-0"><time dateTime={child.birth_date}>{formatDate(child.birth_date)}</time></dd>
                    <dt className="text-dag-muted">Telefone</dt><dd className="m-0">{child.phone}</dd>
                </dl>
                <div className="flex flex-wrap gap-2">
                    <Button asChild><Link href={`/guardian/children/${child.id}/editar`} aria-label={`Editar ${child.name}`}>Editar</Link></Button>
                    <Button asChild><Link href={`/guardian/mesadas/${child.id}`} aria-label={`Ver mesadas de ${child.name}`}>Ver mesadas</Link></Button>
                </div>
            </article>
        ))}
        <dialog ref={dialog} aria-labelledby="register-child-title" onClose={() => setOpen(false)}
            onCancel={event => {
                if (event.currentTarget.querySelector('form[aria-busy="true"]')) event.preventDefault();
            }}
            className="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md overflow-visible rounded-2xl border border-solid border-dag-line bg-white p-0 text-dag-ink shadow-xl backdrop:bg-dag-ink/55">
            <div className="max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-2xl p-5">
                <h2 id="register-child-title">Cadastrar filho</h2>
                {open && <ChildProfileForm onCancel={close}
                    onCreated={() => { close(); setToast({ success: true, text: 'Filho cadastrado com sucesso.' }); }}
                    onFailure={() => setToast({ success: false, text: 'Não foi possível cadastrar. Confira os dados e tente novamente.' })} />}
            </div>
        </dialog>
        <Toast message={toast} container={open ? dialog.current : undefined} />
    </main>;
}
