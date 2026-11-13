import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ChevronDown } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Avatar, AvatarFallback } from '../components/ui/avatar';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import { money } from '../money';
import { nameInitials } from '../tasks';

const monthLabel = (value: string) => `${value.slice(5, 7)}/${value.slice(0, 4)}`;

interface Credit {
    id: number;
    title: string;
    points: number;
    amount_cents: number | null;
}

function PointsBadge({ points }: { points: number }) {
    return <Badge className="px-3 py-1.5">{points} {points === 1 ? 'ponto' : 'pontos'}</Badge>;
}

function ValueBadge({ cents }: { cents: number | null }) {
    return <Badge className="bg-[#ccf1d7] px-3 py-1.5 text-[#1b5632]">
        {cents === null ? 'Aguardando taxa' : money(cents)}
    </Badge>;
}

function PointEntries({ credits, empty, statement = false }: { credits: AllowanceProps['credits']; empty: string; statement?: boolean }) {
    if (credits.length === 0) return <p>{empty}</p>;

    if (statement) {
        return <div className="divide-y divide-solid divide-dag-line">
            {credits.map(credit => (
                <article className="grid gap-1 py-3 first:pt-0 last:pb-0" key={credit.id}>
                    <div className="text-sm text-dag-muted">
                        {credit.completed_at ?? `Aprovada em ${credit.approved_at}`}
                    </div>
                    <strong>{credit.title}</strong>
                    <div className="flex flex-wrap gap-2">
                        <PointsBadge points={credit.points} />
                        <ValueBadge cents={credit.amount_cents} />
                    </div>
                </article>
            ))}
        </div>;
    }

    return credits.map(credit => (
        <article className="ledger-entry" key={credit.id}>
            <p><strong>{credit.title}</strong> · +{credit.points} {credit.points === 1 ? 'ponto' : 'pontos'}</p>
            <p>{credit.approved_at} · {credit.amount_cents === null ? 'Aguardando taxa' : money(credit.amount_cents)}</p>
        </article>
    ));
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
    credits: (Credit & { approved_at: string; completed_at: string | null })[];
    payouts: { id: string; competence: string | null; points: number; amount_cents: number;
        created_at: string; paid_at: string | null; items: Credit[] }[];
}

export default function Allowance(props: AllowanceProps) {
    const { child, isGuardian, rates, preview, payouts, months, credits } = props;
    const endpoint = `/guardian/mesadas/${child.id}`;
    const closing = useForm({ request_id: '', fingerprint: '' });
    const payment = useForm({});
    const statement = useRef<HTMLDialogElement>(null);
    const largestMonth = Math.max(1, ...months.map(month => month.points));

    useEffect(() => {
        const section = window.location.hash.slice(1);
        const target = document.getElementById(section);
        if (!isGuardian && target instanceof HTMLDetailsElement) target.open = true;
        target?.scrollIntoView();
    }, [isGuardian]);

    function close() {
        if (!preview || !window.confirm(`Gerar mesada de ${money(preview.amount_cents)} com ${preview.points} pontos?`)) return;
        closing.transform(() => ({ request_id: preview.request_id, fingerprint: preview.fingerprint }));
        closing.post(`${endpoint}/fechar`, { preserveScroll: true });
    }

    return (
        <main className="screen">
            <Head title={isGuardian ? 'Mesadas' : 'Meus ganhos'} />
            <header className="topbar">
                <h1>{isGuardian ? 'Mesadas' : 'Meus ganhos'}</h1>
                <Button asChild><Link href="/app"><ArrowLeft size={17} aria-hidden="true" /> Voltar</Link></Button>
            </header>
            {isGuardian ? <section className="card grid gap-4" aria-label="Resumo dos ganhos">
                <h2 className="m-0 flex items-center gap-2 text-lg">
                    <Avatar aria-hidden="true"><AvatarFallback>{nameInitials(child.name)}</AvatarFallback></Avatar>
                    {child.name}
                </h2>
                <div className="flex flex-wrap justify-between gap-2">
                    <PointsBadge points={props.availablePoints} />
                    <div className="ml-auto"><ValueBadge cents={props.unpriced ? null : props.availableCents} /></div>
                </div>
                <dl className="m-0 grid grid-cols-2 gap-2">
                    <div className="grid min-w-0 content-center gap-1 rounded-xl bg-[#ffe4a5] p-2 text-center text-sm text-[#694305]">
                        <dt>A receber</dt><dd className="m-0 font-bold wrap-anywhere">{money(props.pendingCents)}</dd>
                    </div>
                    <div className="grid min-w-0 content-center gap-1 rounded-xl bg-[#d9ebff] p-2 text-center text-sm text-[#164875]">
                        <dt>Total Recebido</dt><dd className="m-0 font-bold wrap-anywhere">{money(props.paidCents)}</dd>
                    </div>
                </dl>
            </section> : <section className="card" aria-label="Resumo dos ganhos">
                <p className="tag reward">{props.availablePoints} pontos disponíveis</p>
                <p>{props.unpriced ? 'Há pontos aguardando a primeira conversão.' : `Equivalente a ${money(props.availableCents)}`}</p>
                <p><strong>{money(props.pendingCents)}</strong> a receber</p>
                <p><strong>{money(props.paidCents)}</strong> já recebido</p>
            </section>}
            {isGuardian && (
                <section className="card" id="fechamento">
                    <h2>Encerrar ciclo de mesada</h2>
                    <p>Na virada do mês, o DAG fecha automaticamente o saldo do mês anterior. Você também pode fechar o saldo do mês atual.</p>
                    {preview ? (
                        <div className="mb-4 flex flex-wrap gap-2" aria-label="Saldo a encerrar">
                            <PointsBadge points={preview.points} />
                            <ValueBadge cents={preview.amount_cents} />
                        </div>
                    ) : (
                        <p>{rates.length === 0
                            ? <>Defina o <Link href="/guardian/configuracoes">valor do ponto em Configurações</Link> para fechar a mesada.</>
                            : 'O ciclo fica disponível quando houver pontos aprovados do mês atual fora de uma mesada.'}</p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {preview && <button type="button" disabled={closing.processing} onClick={close}>Encerrar ciclo e gerar conta</button>}
                        <Button type="button" className="cursor-pointer" onClick={() => statement.current?.showModal()}>Ver extrato</Button>
                    </div>
                    <p className="error" role="alert">{closing.errors.fingerprint}</p>
                </section>
            )}
            <details className="card group">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                    <h2 className="m-0">Mesadas</h2><ChevronDown size={19} className="shrink-0 text-dag-purple group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div className="mt-4">
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
                </div>
            </details>
            <details className="card group scroll-mt-20" id="evolucao">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-2 [&::-webkit-details-marker]:hidden">
                    <h2 className="m-0">{isGuardian ? 'Últimos 6 meses' : 'Evolução nos últimos 6 meses'}</h2><ChevronDown size={19} className="shrink-0 text-dag-purple group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div className="mt-4">
                    <div className="point-trend" aria-hidden="true">
                        {[...months].reverse().map(month => (
                            <div key={month.month}>
                                <span>{monthLabel(month.month)}</span>
                                <div><i style={{ width: `${100 * month.points / largestMonth}%` }} /></div>
                                <span>{month.points} pts</span>
                            </div>
                        ))}
                    </div>
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
                </div>
            </details>
            {isGuardian ? <dialog ref={statement} aria-labelledby="points-statement-title"
                className="fixed inset-0 m-auto h-[70dvh] w-[80vw] max-w-none overflow-hidden rounded-2xl border border-solid border-dag-line bg-white p-0 text-dag-ink shadow-xl backdrop:bg-dag-ink/55">
                <div className="flex h-full min-h-0 flex-col">
                    <header className="flex items-center justify-between gap-2 p-4">
                        <h2 id="points-statement-title" className="m-0! text-lg">Extrato de Pontos</h2>
                        <Button type="button" autoFocus className="shrink-0 cursor-pointer" onClick={() => statement.current?.close()}>Fechar</Button>
                    </header>
                    <div className="min-h-0 flex-1 overflow-y-auto p-4" tabIndex={0} role="region" aria-label="Pontos fora de ciclos encerrados">
                        <PointEntries statement credits={credits} empty="Nenhum ponto disponível fora de um ciclo encerrado." />
                    </div>
                </div>
            </dialog> : <section className="card">
                <h2>Extrato de pontos</h2>
                <PointEntries credits={credits} empty="Nenhum ponto aprovado neste período." />
            </section>}
        </main>
    );
}
