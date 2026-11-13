import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import { HttpResponseError } from '@inertiajs/core';
import { ArrowLeft, ChevronDown, LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Avatar, AvatarFallback } from '../components/ui/avatar';
import { Badge } from '../components/ui/badge';
import { Button } from '../components/ui/button';
import { Toast, useToast } from '../components/ui/toast';
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
        return <div>
            {credits.map(credit => (
                <article className="grid gap-1 border-x-0 border-b-0 border-t border-solid border-dag-line py-3 first:border-t-0 first:pt-0 last:pb-0" key={credit.id}>
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
    const closing = useHttp<{ request_id: string; fingerprint: string }, { closed: boolean }>({ request_id: '', fingerprint: '' });
    const payment = useForm({});
    const statement = useRef<HTMLDialogElement>(null);
    const confirmation = useRef<HTMLDialogElement>(null);
    const { toast, setToast } = useToast();
    const [closed, setClosed] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [refreshFailed, setRefreshFailed] = useState(false);
    const [stale, setStale] = useState(false);
    const largestMonth = Math.max(1, ...months.map(month => month.points));

    useEffect(() => {
        const section = window.location.hash.slice(1);
        const target = document.getElementById(section);
        if (!isGuardian && target instanceof HTMLDetailsElement) target.open = true;
        target?.scrollIntoView();
    }, [isGuardian]);

    function refreshAllowance() {
        if (refreshing) return;
        setClosed(false);
        setRefreshing(true);
        setRefreshFailed(false);
        const onFailure = () => { setRefreshFailed(true); return false as const; };
        router.reload({
            only: ['availablePoints', 'availableCents', 'unpriced', 'pendingCents', 'paidCents', 'preview', 'months', 'credits', 'payouts'],
            onSuccess: () => { setStale(false); },
            onHttpException: onFailure, onNetworkError: onFailure, onError: onFailure, onCancel: onFailure,
            onFinish: () => setRefreshing(false),
        });
    }

    async function close() {
        if (!preview || closing.processing || stale) return;
        setToast(null);
        closing.transform(() => ({ request_id: preview.request_id, fingerprint: preview.fingerprint }));
        try {
            const result = await closing.post(`${endpoint}/fechar`);
            if (!result.closed) throw new Error('Fechamento não confirmado');
            confirmation.current?.close();
            setClosed(true);
        } catch (error) {
            const balanceChanged = error instanceof HttpResponseError && error.response.status === 409;
            setStale(balanceChanged);
            setToast({ success: false, text: balanceChanged
                ? 'O saldo mudou. Atualize os valores para confirmar novamente.'
                : 'Não foi possível encerrar o ciclo. Tente novamente.' });
        }
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
                    {!preview && (
                        <p>{rates.length === 0
                            ? <>Defina o <Link href="/guardian/configuracoes">valor do ponto em Configurações</Link> para fechar a mesada.</>
                            : 'O ciclo fica disponível quando houver pontos aprovados do mês atual fora de uma mesada.'}</p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {preview && <button type="button" disabled={closed || refreshing || refreshFailed}
                            onClick={() => { setToast(null); confirmation.current?.showModal(); }}>Encerrar ciclo e gerar conta</button>}
                        <Button type="button" className="cursor-pointer" disabled={closed || refreshing || refreshFailed}
                            onClick={() => statement.current?.showModal()}>Ver extrato</Button>
                    </div>
                    {refreshing && <p className="mt-2" role="status">Atualizando mesadas…</p>}
                    {refreshFailed && <p className="mt-2" role="alert">
                        Não foi possível atualizar as informações.{' '}
                        <Button type="button" onClick={refreshAllowance}>Tentar novamente</Button>
                    </p>}
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
                                <button type="button" className="secondary" disabled={payment.processing || closing.processing || closed || refreshing || refreshFailed}
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
            {isGuardian && <dialog ref={confirmation} aria-labelledby="close-cycle-title" aria-describedby="close-cycle-description"
                aria-busy={closing.processing || refreshing} onCancel={event => {
                    if (closing.processing || refreshing) event.preventDefault();
                }}
                className="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-solid border-dag-line bg-white p-5 text-dag-ink shadow-xl backdrop:bg-dag-ink/55">
                <h2 id="close-cycle-title">Encerrar ciclo de mesada?</h2>
                {preview ? <p id="close-cycle-description" className="leading-loose">
                    Deseja encerrar esse ciclo de <PointsBadge points={preview.points} /> e gerar uma conta de <ValueBadge cents={preview.amount_cents} />?
                </p> : <p id="close-cycle-description">Nenhum saldo disponível para encerrar.</p>}
                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" autoFocus disabled={closing.processing || refreshing} onClick={() => confirmation.current?.close()}>Cancelar</Button>
                    {stale
                        ? <Button type="button" disabled={refreshing} onClick={refreshAllowance}>
                            {refreshing ? 'Atualizando…' : 'Atualizar valores'}
                        </Button>
                        : <Button type="button" disabled={!preview || closing.processing} className="bg-dag-purple text-white hover:bg-[#452497]"
                            onClick={() => { void close(); }}>
                            {closing.processing && <LoaderCircle size={17} className="motion-safe:animate-spin" aria-hidden="true" />}
                            {closing.processing ? 'Encerrando…' : 'Sim, confirmar'}
                        </Button>}
                </div>
                {stale && refreshFailed && <p className="mt-2" role="alert">Não foi possível atualizar os valores. Tente novamente.</p>}
                <Toast message={toast} container={confirmation.current} />
            </dialog>}
            <Toast message={closed ? { success: true, text: 'Ciclo encerrado e conta gerada com sucesso.' } : null}
                onConfirm={closed ? refreshAllowance : undefined} />
        </main>
    );
}
