import { router } from '@inertiajs/react';
import type { MouseEvent } from 'react';

export interface Notice {
    id: string;
    title: string;
    message: string;
    href: string;
    unread: boolean;
    occurred_at: string;
}

export interface NoticeSummary {
    unread: number;
    events: Notice[];
}

export async function openNotice(event: MouseEvent<HTMLAnchorElement>, notice: Notice, close?: () => void) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();

    if (notice.unread) {
        try {
            const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
            if (cookie) {
                await fetch('/app/avisos/lidos', {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)),
                    },
                    body: JSON.stringify({ ids: [notice.id] }),
                    signal: AbortSignal.timeout(3000),
                });
            }
        } catch {
            // A missão continua acessível mesmo se a confirmação de leitura falhar.
        }
    }

    close?.();
    router.visit(notice.href);
}
