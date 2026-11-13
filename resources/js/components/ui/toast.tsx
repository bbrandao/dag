import { Check, CircleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

interface ToastMessage {
    success: boolean;
    text: string;
}

export function useToast() {
    const [toast, setToast] = useState<ToastMessage | null>(null);

    useEffect(() => {
        if (!toast) return;
        const timeout = window.setTimeout(() => setToast(null), 3500);
        return () => window.clearTimeout(timeout);
    }, [toast]);

    return { toast, setToast };
}

export function Toast({ message, container, onConfirm }: {
    message: ToastMessage | null;
    container?: HTMLElement | null;
    onConfirm?: () => void;
}) {
    if (!message) return null;
    const Icon = message.success ? Check : CircleAlert;

    return createPortal(
        <div className="pointer-events-none fixed inset-0 z-60 grid place-items-center px-5">
            <div role={message.success ? 'status' : 'alert'} className={`flex max-w-sm items-center gap-3 rounded-xl border border-solid px-4 py-3 text-sm font-bold shadow-xl ${
                message.success ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900'
            }`}>
                <Icon aria-hidden="true" className="size-5 shrink-0" />
                {message.text}
                {onConfirm && <button type="button" autoFocus className="pointer-events-auto" onClick={onConfirm}>OK</button>}
            </div>
        </div>, container ?? document.body,
    );
}
