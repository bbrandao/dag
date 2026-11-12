import * as PopoverPrimitive from '@radix-ui/react-popover';
import type { ComponentProps } from 'react';
import { twMerge } from 'tailwind-merge';

export const Popover = PopoverPrimitive.Root;
export const PopoverTrigger = PopoverPrimitive.Trigger;

export function PopoverContent({ className, align = 'end', sideOffset = 10, ...props }: ComponentProps<typeof PopoverPrimitive.Content>) {
    return <PopoverPrimitive.Portal>
        <PopoverPrimitive.Content data-slot="popover-content" align={align} sideOffset={sideOffset}
            collisionPadding={12} className={twMerge(
                'z-50 max-h-(--radix-popover-content-available-height) w-80 max-w-[calc(100dvw-1.5rem)] overflow-y-auto rounded-2xl border border-solid border-dag-line bg-white p-3 text-dag-ink shadow-xl',
                className,
            )} {...props} />
    </PopoverPrimitive.Portal>;
}
