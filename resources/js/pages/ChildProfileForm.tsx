import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export interface ChildProfile {
    id: string;
    name: string;
    birth_date: string;
    phone: string;
}

type FormProps = { child: ChildProfile; onCreated?: never; onFailure?: never; onCancel?: never }
    | { child?: never; onCreated: () => void; onFailure: () => void; onCancel: () => void };

export default function ChildProfileForm({ child, onCreated, onFailure, onCancel }: FormProps) {
    const form = useForm({
        name: child?.name ?? '',
        birth_date: child?.birth_date ?? '',
        phone: child?.phone ?? '+55',
        authorized: false,
    });

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (child) {
            form.patch(`/guardian/children/${child.id}?from=children`);
        } else {
            const handleFailure = () => { onFailure(); return false as const; };
            form.post('/guardian/children?from=children', {
                preserveScroll: true,
                onSuccess: onCreated,
                onError: onFailure,
                onHttpException: handleFailure,
                onNetworkError: handleFailure,
            });
        }
    }

    return (
        <form onSubmit={save} aria-busy={form.processing}>
            <label htmlFor="child-name">Nome</label>
            <input id="child-name" autoFocus={!child} required maxLength={120} value={form.data.name}
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
            {child ? (
                form.data.phone !== child.phone && <p role="status">Ao salvar, o perfil sairá das sessões atuais. O próximo acesso usará o novo WhatsApp.</p>
            ) : (
                <>
                    <label className="check">
                        <input type="checkbox" required checked={form.data.authorized}
                            onChange={event => form.setData('authorized', event.target.checked)} />
                        Confirmo que tenho autorização para cadastrar este perfil.
                    </label>
                    <p className="error" role="alert">{form.errors.authorized}</p>
                </>
            )}
            <button type="submit" disabled={form.processing}>{child ? 'Salvar alterações' : 'Cadastrar perfil'}</button>
            {child
                ? <Link className="button secondary" href="/guardian/children">Cancelar</Link>
                : <button type="button" className="secondary" disabled={form.processing} onClick={onCancel}>Cancelar</button>}
        </form>
    );
}
