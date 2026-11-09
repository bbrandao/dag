import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, type FormEvent } from 'react';

export default function Login({ awaitingCode }: { awaitingCode: boolean }) {
    const request = useForm({ phone: '+55', permission: false });
    const verification = useForm({ code: '' });
    const codeInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (awaitingCode) codeInput.current?.focus();
    }, [awaitingCode]);

    function requestCode(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        request.post('/auth/otp');
    }

    function signIn(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        verification.post('/auth/otp/verify', {
            onFinish: () => verification.reset('code'),
        });
    }

    return (
        <main className="screen login">
            <p className="brand"><span aria-hidden="true">✓</span> Do And Gain</p>
            <section className="card" aria-labelledby="login-title">
                <Head title="Entrar" />
                <h1 id="login-title">{awaitingCode ? 'Confira seu WhatsApp' : 'Bora começar?'}</h1>
                {awaitingCode ? (
                    <form onSubmit={signIn}>
                        <p>Se o número estiver cadastrado, o DAG envia um código de seis dígitos.</p>
                        <label htmlFor="code">Código de acesso</label>
                        <input id="code" ref={codeInput} inputMode="numeric" autoComplete="one-time-code"
                            required minLength={6} maxLength={6} pattern="[0-9]{6}" value={verification.data.code}
                            aria-invalid={Boolean(verification.errors.code)} aria-describedby="code-error"
                            onChange={event => verification.setData('code', event.target.value)} />
                        <p id="code-error" className="error" role="alert">{verification.errors.code}</p>
                        <button disabled={verification.processing} type="submit">Entrar</button>
                        <button className="secondary" type="button" disabled={verification.processing}
                            onClick={() => router.post('/auth/otp/reset', {}, { onSuccess: () => verification.clearErrors() })}>
                            Solicitar outro código ou mudar número
                        </button>
                    </form>
                ) : (
                    <form onSubmit={requestCode}>
                        <p>Entre com seu WhatsApp para abrir seu espaço no DAG.</p>
                        <label htmlFor="phone">WhatsApp com código do país</label>
                        <input id="phone" type="tel" autoComplete="tel" maxLength={16} required
                            placeholder="+5571999999999" value={request.data.phone}
                            aria-invalid={Boolean(request.errors.phone)} aria-describedby="phone-error"
                            onChange={event => request.setData('phone', event.target.value)} />
                        <p id="phone-error" className="error" role="alert">{request.errors.phone}</p>
                        <label className="check">
                            <input type="checkbox" required checked={request.data.permission}
                                onChange={event => request.setData('permission', event.target.checked)} />
                            Quero receber meu código de acesso pelo WhatsApp.
                        </label>
                        <p className="error" role="alert">{request.errors.permission}</p>
                        <button disabled={request.processing} type="submit">Receber código</button>
                    </form>
                )}
            </section>
        </main>
    );
}
