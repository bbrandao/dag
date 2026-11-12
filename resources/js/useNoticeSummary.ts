import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { NoticeSummary } from './notices';

const interval = 30000;

export function useNoticeSummary() {
    const { component, props, url } = usePage<{ noticeSummary?: NoticeSummary }>();
    const onInbox = component === 'Inbox';
    const [summary, setSummary] = useState<NoticeSummary>();
    const [failed, setFailed] = useState(false);
    const refreshNow = useRef<() => void>(() => {});

    useEffect(() => {
        let disposed = false;
        let running = false;
        let retryAfter = 0;
        let delay = interval;
        let controller: AbortController | undefined;
        let cancelReload: (() => void) | undefined;

        function success() {
            if (disposed) return;
            retryAfter = 0;
            delay = interval;
            setFailed(false);
        }

        function failure() {
            if (disposed) return;
            retryAfter = Date.now() + delay;
            delay = Math.min(delay * 2, 120000);
            setFailed(true);
            return false as const;
        }

        async function refresh(force = false) {
            if (disposed || running || document.hidden || !navigator.onLine || (!force && Date.now() < retryAfter)) return;
            running = true;

            if (onInbox) {
                router.reload({
                    only: ['events', 'pagination', 'noticeSummary'],
                    onCancelToken: token => { cancelReload = () => token.cancel(); },
                    onSuccess: success,
                    onHttpException: failure,
                    onNetworkError: failure,
                    onFinish: () => { running = false; cancelReload = undefined; },
                });
                return;
            }

            controller = new AbortController();
            try {
                const response = await fetch('/app/avisos/resumo', {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (response.status === 401) {
                    if (!disposed) setSummary(undefined);
                    return;
                }
                if (!response.ok) throw new Error('Falha ao atualizar avisos.');
                const data: NoticeSummary = await response.json();
                if (!disposed) setSummary(data);
                success();
            } catch {
                failure();
            } finally {
                running = false;
            }
        }

        const resume = () => {
            if (!navigator.onLine) {
                setFailed(true);
                return;
            }
            void refresh(true);
        };
        refreshNow.current = resume;
        const timer = window.setInterval(() => { void refresh(); }, interval);
        const unsubscribe = router.on('finish', event => {
            if (!onInbox && event.detail.visit.method !== 'get') resume();
        });
        document.addEventListener('visibilitychange', resume);
        window.addEventListener('online', resume);
        if (!onInbox) resume();

        return () => {
            disposed = true;
            refreshNow.current = () => {};
            controller?.abort();
            cancelReload?.();
            unsubscribe();
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', resume);
            window.removeEventListener('online', resume);
        };
    }, [onInbox, url]);

    return { summary: onInbox ? props.noticeSummary : summary, failed, refresh: () => refreshNow.current() };
}
