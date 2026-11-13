import { Link } from '@inertiajs/react';
import { Avatar, AvatarFallback } from './components/ui/avatar';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';
import { money } from './money';
import { nameInitials } from './tasks';

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

function Period({ label, progress, reviewUrl }: { label: string; progress: PeriodProgress; reviewUrl?: string }) {
    return <section className="border-0 border-t border-solid border-dag-line pt-3">
        <h4 className="mb-2 text-sm font-bold">{label}</h4>
        <div className={`grid gap-1.5 ${reviewUrl ? 'grid-cols-4' : 'grid-cols-3'}`}>
            <div className={`${box} bg-[#ffe4a5] text-[#694305]`}>
                <span>A fazer</span><strong className="text-lg tabular-nums">{number.format(progress.open)}</strong>
            </div>
            <div className={`${box} bg-[#ccf1d7] text-[#1b5632]`}>
                <span>Concluídas</span><strong className="text-lg tabular-nums">{number.format(progress.approved)}</strong>
            </div>
            <div className={`${box} bg-[#f9d5cc] text-[#78271e]`}>
                <span>Vencidas</span><strong className="text-lg tabular-nums">{number.format(progress.expired)}</strong>
            </div>
            {reviewUrl && <Link href={reviewUrl}
                className={`${box} bg-[#d9ebff] font-semibold text-[#164875] no-underline hover:bg-[#bddfff]`}>
                <span>Em revisão</span><strong className="text-lg tabular-nums">{number.format(progress.inReview)}</strong>
            </Link>}
        </div>
        <div className="mt-2 flex flex-wrap items-center gap-2">
            <Badge className="bg-dag-lilac px-2 py-1">{number.format(progress.earned)} pontos conquistados</Badge>
            <Badge className="bg-dag-lilac px-2 py-1">
                {percent.format(progress.possible ? progress.earned / progress.possible : 0)} dos pontos possíveis
            </Badge>
        </div>
    </section>;
}

export function ChildProgressCard({ child, today }: { child: GuardianProgress; today: string }) {
    const reviews = `/guardian/revisoes?child=${encodeURIComponent(child.id)}`;

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
        <Period label="Hoje" progress={child.periods.day} reviewUrl={`${reviews}&date=${today}&status=in_review`} />
        <Period label="Esta semana" progress={child.periods.week} />
        <Period label="Este mês" progress={child.periods.month} />
        <div className="flex flex-wrap gap-2">
            <Button asChild><Link href={`/guardian/mesadas/${child.id}#evolucao`}>Ver evolução e extrato</Link></Button>
            <Button asChild><Link href={reviews}>Revisar Missões</Link></Button>
        </div>
    </article>;
}
