import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { Avatar, AvatarFallback } from './components/ui/avatar';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';
import { money } from './money';
import { months, nameInitials } from './tasks';

interface PeriodProgress {
    open: number;
    approved: number;
    expired: number;
    inReview: number;
    earned: number;
    possible: number;
}

export interface GuardianProgress {
    id: string;
    name: string;
    availablePoints: number;
    availableCents: number;
    awaitingConversion: boolean;
    periods: { day: PeriodProgress; week: PeriodProgress; month: PeriodProgress };
}

const number = new Intl.NumberFormat('pt-BR');
const percent = new Intl.NumberFormat('pt-BR', { style: 'percent', maximumFractionDigits: 1 });
const box = 'flex min-w-0 flex-col items-center justify-center gap-1 rounded-lg px-0.5 py-2 text-center text-[0.6875rem] leading-tight sm:px-2 sm:text-xs';

function Period({ label, progress, showReview, reviewUrl, month }: {
    label: string; progress: PeriodProgress; showReview?: boolean; reviewUrl?: string; month?: string;
}) {
    const review = <><span>Em revisão</span><strong className="text-lg tabular-nums">{number.format(progress.inReview)}</strong></>;
    const reviewStyle = `${box} bg-[#d9ebff] text-[#164875]`;
    return <section className="border-0 border-t border-solid border-dag-line pt-3">
        <h4 className="mb-2 flex items-center gap-2 text-sm font-bold">
            {label}
            {month && <Badge className="bg-dag-lilac px-2 py-1">{month}</Badge>}
        </h4>
        <div className={`grid gap-1.5 ${showReview ? 'grid-cols-4' : 'grid-cols-3'}`}>
            <div className={`${box} bg-[#ffe4a5] text-[#694305]`}>
                <span>A fazer</span><strong className="text-lg tabular-nums">{number.format(progress.open)}</strong>
            </div>
            <div className={`${box} bg-[#ccf1d7] text-[#1b5632]`}>
                <span>Concluídas</span><strong className="text-lg tabular-nums">{number.format(progress.approved)}</strong>
            </div>
            <div className={`${box} bg-[#f9d5cc] text-[#78271e]`}>
                <span>Vencidas</span><strong className="text-lg tabular-nums">{number.format(progress.expired)}</strong>
            </div>
            {showReview && (reviewUrl
                ? <Link href={reviewUrl} className={`${reviewStyle} font-semibold no-underline hover:bg-[#bddfff]`}>{review}</Link>
                : <div className={reviewStyle}>{review}</div>)}
        </div>
        <div className="mt-2 flex flex-wrap items-center gap-2">
            <Badge className="bg-dag-lilac px-2 py-1">{number.format(progress.earned)} {progress.earned === 1 ? 'ponto' : 'pontos'}</Badge>
            <Badge className="bg-dag-lilac px-2 py-1">
                {percent.format(progress.possible ? progress.earned / progress.possible : 0)}
            </Badge>
        </div>
    </section>;
}

export function ChildProgressCard({ child, today, isGuardian = true }: {
    child: GuardianProgress; today: string; isGuardian?: boolean;
}) {
    const reviews = `/guardian/revisoes?child=${encodeURIComponent(child.id)}`;
    const [year, month] = today.split('-');
    const monthLabel = `${months[Number(month) - 1].slice(0, 3)}/${year}`;
    const periods = <>
        <Period label="Hoje" progress={child.periods.day} showReview reviewUrl={isGuardian ? `${reviews}&date=${today}&status=in_review` : undefined} />
        <Period label="Esta semana" progress={child.periods.week} />
        <Period label="Este mês" month={monthLabel} progress={child.periods.month} />
    </>;

    return <article className="card grid gap-4">
        <h3 className="m-0 flex items-center gap-2 text-base">
            <Avatar aria-hidden="true"><AvatarFallback>{nameInitials(child.name)}</AvatarFallback></Avatar>
            {child.name}
        </h3>
        <div className="flex flex-wrap items-center justify-between gap-2" aria-label="Saldo de pontos em aberto">
            <Badge className="px-3 py-1.5">{number.format(child.availablePoints)} pontos</Badge>
            <Badge className="ml-auto bg-[#ccf1d7] px-3 py-1.5 text-[#1b5632]">
                {child.awaitingConversion ? 'Aguardando taxa' : money(child.availableCents)}
            </Badge>
        </div>
        {isGuardian ? periods : <details className="group">
            <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 text-sm font-bold [&::-webkit-details-marker]:hidden">
                Meu progresso
                <ChevronDown size={19} className="shrink-0 text-dag-purple group-open:rotate-180" aria-hidden="true" />
            </summary>
            <div className="grid gap-4 pt-2">{periods}</div>
        </details>}
        <div className="mt-2 flex flex-wrap gap-2">
            <Button asChild className="bg-dag-purple text-white hover:bg-[#452497]">
                {isGuardian ? <Link href={`/guardian/mesadas/${child.id}#evolucao`}>Ver evolução e extrato</Link>
                    : <Link href="/app/ganhos">Ver meus ganhos</Link>}
            </Button>
            <Button asChild>{isGuardian ? <Link href={reviews}>Revisar Missões</Link>
                : <Link href="/app/missoes">Ver minhas missões</Link>}</Button>
        </div>
    </article>;
}
