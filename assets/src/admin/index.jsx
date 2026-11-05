import { h, createRoot, apiFetch } from './wp';
import Builder from './Builder';
import Settings from './Settings';
import Dashboard from './Dashboard';
const root = document.getElementById('plainforms-admin-app');
const source = document.getElementById('plainforms-admin-bootstrap');
if (root && source) {
    try {
        const bootstrap = JSON.parse(source.textContent);
        if (!apiFetch.nonceMiddleware) {
            apiFetch.nonceMiddleware = apiFetch.createNonceMiddleware(
                bootstrap.nonce,
            );
            apiFetch.use(apiFetch.nonceMiddleware);
        }
        const Screen = {
            builder: Builder,
            settings: Settings,
            dashboard: Dashboard,
        }[bootstrap.screen];
        if (!Screen) throw new Error('Unknown screen');
        createRoot(root).render(
            <Screen data={bootstrap.data} links={bootstrap.links} />,
        );
    } catch (error) {
        root.replaceChildren();
        const notice = document.createElement('p');
        notice.className = 'notice notice-error';
        notice.setAttribute('role', 'alert');
        notice.textContent =
            'The Plain Forms interface could not load. Please reload this page.';
        root.append(notice);
    }
}
