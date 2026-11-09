import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import PwaBar from './PwaBar';
import Allowance from './pages/Allowance';
import Dashboard from './pages/Dashboard';
import EditChild from './pages/EditChild';
import Inbox from './pages/Inbox';
import Login from './pages/Login';
import Missions from './pages/Missions';
import Mission from './pages/Mission';
import Reviews from './pages/Reviews';
import Tasks from './pages/Tasks';
import '../css/app.css';

const pages = { Allowance, Dashboard, EditChild, Inbox, Login, Mission, Missions, Reviews, Tasks };

void createInertiaApp({
    title: title => `${title} · DAG`,
    resolve: name => pages[name as keyof typeof pages],
    setup({ el, App, props }) {
        createRoot(el).render(
            <>
                <PwaBar initiallyAuthenticated={props.initialPage.props.authenticated === true} />
                <App {...props} />
            </>,
        );
    },
});

if (window.isSecureContext && 'serviceWorker' in navigator) {
    void navigator.serviceWorker.register('/sw.js').catch(() => {});
}
