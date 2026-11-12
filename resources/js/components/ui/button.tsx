import { Slot } from '@radix-ui/react-slot';
import type { ComponentProps } from 'react';
import { twMerge } from 'tailwind-merge';

export function Button({ asChild = false, className, ...props }: ComponentProps<'button'> & { asChild?: boolean }) {
    const Component = asChild ? Slot : 'button';

    return <Component data-slot="button" className={twMerge(
        'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border-0 bg-dag-lilac px-3 text-sm font-bold text-dag-ink no-underline hover:bg-dag-line disabled:opacity-60',
        className,
    )} {...props} />;
}
