import * as AvatarPrimitive from '@radix-ui/react-avatar';
import type { ComponentProps } from 'react';

export function Avatar({ children, ...props }: ComponentProps<typeof AvatarPrimitive.Root>) {
    return <AvatarPrimitive.Root data-slot="avatar" className="inline-flex size-9 shrink-0 overflow-hidden rounded-full" {...props}>
        {children}
    </AvatarPrimitive.Root>;
}

export function AvatarFallback(props: ComponentProps<typeof AvatarPrimitive.Fallback>) {
    return <AvatarPrimitive.Fallback data-slot="avatar-fallback"
        className="grid size-full place-items-center bg-dag-purple font-bold text-white" {...props} />;
}
