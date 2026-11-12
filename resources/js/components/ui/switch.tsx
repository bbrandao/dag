import type { ComponentProps } from 'react';

export function Switch({ checked, children, ...props }: ComponentProps<'button'> & { checked: boolean }) {
    return <button {...props} type="button" role="switch" aria-checked={checked} data-slot="button"
        className="group flex min-h-11 w-full cursor-pointer items-center justify-between gap-3 rounded-lg border-0 bg-transparent px-2 text-left text-sm font-semibold text-dag-ink hover:bg-dag-lilac disabled:cursor-default disabled:opacity-65">
        {children}
        <span aria-hidden="true" className="relative h-6 w-11 shrink-0 rounded-full bg-dag-muted transition-colors group-aria-checked:bg-dag-purple">
            <span className="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow-sm transition-transform group-aria-checked:translate-x-5" />
        </span>
    </button>;
}
