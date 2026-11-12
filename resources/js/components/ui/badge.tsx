import type { ComponentProps } from 'react';
import { twMerge } from 'tailwind-merge';

export function Badge({ className, ...props }: ComponentProps<'span'>) {
    return <span data-slot="badge" className={twMerge(
        'inline-flex min-w-5 items-center justify-center rounded-full bg-dag-gold px-1.5 py-0.5 text-xs leading-none font-bold text-dag-ink',
        className,
    )} {...props} />;
}
