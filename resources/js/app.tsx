import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Dashboard from './pages/Dashboard';
import Login from './pages/Login';
import Missions from './pages/Missions';
import Mission from './pages/Mission';
import Reviews from './pages/Reviews';
import Tasks from './pages/Tasks';
import '../css/app.css';

const pages = { Dashboard, Login, Mission, Missions, Reviews, Tasks };

void createInertiaApp({
    title: title => `${title} · DAG`,
    resolve: name => pages[name as keyof typeof pages],
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
