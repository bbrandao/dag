import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

const reais = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
const money = (cents: number) => reais.format(cents / 100);
const monthLabel = (value: string) => `${value.slice(5, 7)}/${value.slice(0, 4)}`;

interface Credit {
    id: number;
    title: string;
    points: number;
    amount_cents: number | null;
}

interface AllowanceProps {
    child: { id: string; name: string };
    isGuardian: boolean;
    availablePoints: number;
    availableCents: number;
    unpriced: boolean;
    pendingCents: number;
    paidCents: number;
    rates: { id: number; cents_per_point: number; effective_at: string }[];
    preview: { request_id: string; fingerprint: string; points: number; amount_cents: number } | null;
    months: { month: string; points: number; earned_cents: number; unpriced: boolean; paid_cents: number }[];
    credits: (Credit & { approved_at: string })[];
    payouts: { id: string; competence: string | null; points: number; amount_cents: number;
        created_at: string; paid_at: string | null; items: Credit[] }[];
}

export default function Allowance(props: AllowanceProps) {
    const { child, isGuardian, rates, preview, payouts, months, credits } = props;
    const endpoint = `/guardian/mesadas/${child.id}`;
    const rate = useForm({ cents_per_point: rates[0]?.cents_per_point ?? 10 });
    const closing = useForm({ request_id: '', fingerprint: '' });
    const payment = useForm({});

    function setRate(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        rate.post(`${endpoint}/conversao`, { preserveScroll: true });
    }

    function close() {
        if (!preview || !window.confirm(`Gerar mesada de ${money(preview.amount_cents)} com ${preview.points} pontos?`)) return;
        closing.transform(() => ({ request_id: preview.request_id, fingerprint: preview.fingerprint }));
        closing.post(`${endpoint}/fechar`, { preserveScroll: true });
    }

    return (
        <main className="screen">
            <Head title={isGuardian ? 'Mesadas' : 'Meus ganhos'} />
            <header className="topbar">
                <h1>{isGuardian ? `Mesadas de ${child.name}` : 'Meus ganhos'}</h1>
                <Link className="button secondary" href="/app">Voltar</Link>
            </header>
            <section className="card" aria-label="Resumo dos ganhos">
                <p className="tag reward">{props.availablePoints} pontos disponíveis</p>
                <p>{props.unpriced ? 'Há pontos aguardando a primeira conversão.' : `Equivalente a ${money(props.availableCents)}`}</p>
                <p><strong>{money(props.pendingCents)}</strong> a receber</p>
                <p><strong>{money(props.paidCents)}</strong> já recebido</p>
            </section>
            {isGuardian && (
                <>
                    <section className="card">
                        <h2>Conversão da família</h2>
                        <p>A primeira taxa também dá valor aos pontos já aprovados. As alterações seguintes valem para novas missões.</p>
                        <form onSubmit={setRate}>
                            <label htmlFor="conversion">Centavos por ponto</label>
                            <input id="conversion" type="number" min={1} max={100000} required
                                value={rate.data.cents_per_point || ''}
                                onChange={event => rate.setData('cents_per_point', Number(event.target.value))} />
                            <p>1 ponto = {money(rate.data.cents_per_point)}</p>
                            <p className="error" role="alert">{rate.errors.cents_per_point}</p>
                            <button disabled={rate.processing}>Salvar taxa</button>
                            {rate.recentlySuccessful && <p role="status">Taxa salva.</p>}
                        </form>
                        {rates.length > 0 && <details><summary>Histórico de taxas</summary>
                            <ul>{rates.map(item => <li key={item.id}>{money(item.cents_per_point)} por ponto · {item.effective_at}</li>)}</ul>
                        </details>}
                    </section>
                    <section className="card">
                        <h2>Gerar mesada</h2>
                        <p>Na virada do mês, o DAG fecha automaticamente o saldo do mês anterior. Você também pode fechar o saldo do mês atual.</p>
                        {preview ? (
                            <>
                                <p>{preview.points} pontos serão movidos para uma conta de {money(preview.amount_cents)} a receber.</p>
                                <button type="button" disabled={closing.processing} onClick={close}>Gerar conta</button>
                            </>
                        ) : <p>Nenhum saldo em reais do mês atual está disponível para fechar.</p>}
                        <p className="error" role="alert">{closing.errors.fingerprint}</p>
                    </section>
                </>
            )}
            <section className="card">
                <h2>Mesadas</h2>
                {payouts.length === 0 && <p>Nenhuma mesada gerada.</p>}
                {payouts.map(payout => (
                    <article className="ledger-entry" key={payout.id}>
                        <p><strong>{money(payout.amount_cents)}</strong> · {payout.points} pontos · {payout.paid_at ? 'Paga' : 'A receber'}</p>
                        <p>{payout.competence ? `Fechamento de ${monthLabel(payout.competence)}` : 'Fechamento manual'} · {payout.created_at}</p>
                        {payout.paid_at && <p>Pagamento registrado em {payout.paid_at}</p>}
                        <details><summary>Ver pontos incluídos</summary>
                            <ul>{payout.items.map(item => <li key={item.id}>{item.title} · {item.points} pontos · {money(item.amount_cents ?? 0)}</li>)}</ul>
                        </details>
                        {isGuardian && !payout.paid_at && (
                            <button type="button" className="secondary" disabled={payment.processing}
                                onClick={() => {
                                    if (window.confirm(`Confirmar que você pagou ${money(payout.amount_cents)}?`)) {
                                        payment.patch(`${endpoint}/contas/${payout.id}/paga`, { preserveScroll: true });
                                    }
                                }}>Marcar como paga</button>
                        )}
                    </article>
                ))}
            </section>
            <section className="card">
                <h2>Últimos seis meses</h2>
                <div className="table-scroll">
                    <table>
                        <thead><tr><th scope="col">Mês</th><th scope="col">Pontos</th><th scope="col">Ganhos</th><th scope="col">Pagos</th></tr></thead>
                        <tbody>{months.map(month => (
                            <tr key={month.month}>
                                <th scope="row">{monthLabel(month.month)}</th>
                                <td>{month.points}</td>
                                <td>{month.unpriced ? 'Aguardando taxa' : money(month.earned_cents)}</td>
                                <td>{money(month.paid_cents)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            </section>
            <section className="card">
                <h2>Extrato de pontos</h2>
                {credits.length === 0 && <p>Nenhum ponto aprovado neste período.</p>}
                {credits.map(credit => (
                    <article className="ledger-entry" key={credit.id}>
                        <p><strong>{credit.title}</strong> · +{credit.points} pontos</p>
                        <p>{credit.approved_at} · {credit.amount_cents === null ? 'Aguardando taxa' : money(credit.amount_cents)}</p>
                    </article>
                ))}
            </section>
        </main>
    );
}
