import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { type FormEvent } from 'react';
import { Button } from '../components/ui/button';
import { Toast, useToast } from '../components/ui/toast';
import { money } from '../money';

interface PointRate {
    id: number;
    cents_per_point: number;
    effective_at: string;
    recorded_at: string | null;
}

export default function Settings({ rates }: { rates: PointRate[] }) {
    const current = rates[0]?.cents_per_point;
    const rate = useForm({ cents_per_point: current ?? 10 });
    const { toast, setToast } = useToast();

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (rate.processing) return;
        setToast(null);
        const showError = () => {
            setToast({ success: false, text: 'Não foi possível salvar o valor do ponto. Confira os dados e tente novamente.' });
            return false as const;
        };

        rate.post('/guardian/configuracoes/valor-do-ponto', {
            only: ['rates', 'errors'],
            preserveScroll: true,
            onSuccess: () => setToast({ success: true, text: 'Valor do ponto salvo.' }),
            onError: showError,
            onHttpException: showError,
            onNetworkError: showError,
        });
    }

    return <main className="screen">
        <Head title="Configurações" />
        <header className="topbar flex-wrap">
            <h1>Configurações</h1>
            <Button asChild><Link href="/app" aria-disabled={rate.processing || undefined}
                tabIndex={rate.processing ? -1 : undefined} className="aria-disabled:cursor-default aria-disabled:opacity-60"
                onClick={event => { if (rate.processing) event.preventDefault(); }}>
                <ArrowLeft size={17} aria-hidden="true" /> Voltar
            </Link></Button>
        </header>
        <section className="card" id="conversao">
            <h2>Valor do ponto</h2>
            <p>A primeira taxa também dá valor aos pontos já aprovados. As alterações seguintes valem para novas missões.</p>
            <form onSubmit={save} aria-busy={rate.processing}>
                <label htmlFor="conversion">Centavos por ponto</label>
                <input id="conversion" type="number" inputMode="numeric" min={1} max={100000} required
                    disabled={rate.processing}
                    value={rate.data.cents_per_point || ''} aria-describedby="conversion-preview conversion-error"
                    aria-invalid={Boolean(rate.errors.cents_per_point)}
                    onChange={event => rate.setData('cents_per_point', Number(event.target.value))} />
                <p id="conversion-preview">1 ponto = {money(rate.data.cents_per_point)}</p>
                <p id="conversion-error" className="error" role="alert">{rate.errors.cents_per_point}</p>
                <Button className="cursor-pointer bg-dag-purple text-white hover:bg-[#452497]" type="submit" disabled={rate.processing}>
                    {rate.processing && <LoaderCircle size={17} className="motion-safe:animate-spin" aria-hidden="true" />}
                    {rate.processing ? 'Salvando…' : 'Salvar valor'}
                </Button>
            </form>
        </section>
        <div role="status" className="sr-only">{rate.processing ? 'Salvando valor do ponto e atualizando o histórico.' : ''}</div>
        <section className="card" aria-labelledby="rate-history-title" aria-busy={rate.processing}>
            <h2 id="rate-history-title">Histórico de valores</h2>
            {rates.length === 0 ? <p>Nenhum valor cadastrado.</p> : (
                <div className="max-h-48 overflow-auto rounded-lg border border-solid border-dag-line" tabIndex={rates.length > 3 ? 0 : undefined}
                    role="region" aria-label="Histórico de valores do ponto">
                    <table className="w-full border-collapse text-left text-sm">
                        <thead className="sticky top-0 bg-dag-lilac">
                            <tr className="h-12"><th scope="col" className="px-3">Data e hora</th><th scope="col" className="px-3">Valor do ponto</th></tr>
                        </thead>
                        <tbody>{rates.map(item => (
                            <tr key={item.id} className="h-12 odd:bg-white even:bg-[#faf6ff]">
                                <td className="px-3">{item.recorded_at ?? item.effective_at}</td>
                                <td className="px-3">{money(item.cents_per_point)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            )}
            {rates.some(item => item.recorded_at === null) && <p className="mt-2 text-sm text-dag-muted">
                Para registros antigos, a tabela mostra o início da vigência.
            </p>}
        </section>
        <Toast message={toast} />
    </main>;
}
