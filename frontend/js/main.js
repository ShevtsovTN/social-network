import { createApi } from './api.js';
import { el } from './dom.js';
import { BACKENDS, clearSession, getBackend, getSession, setBackend } from './session.js';
import { renderLogin } from './views/login.js';
import { renderProfile } from './views/profile.js';
import { renderRegister } from './views/register.js';

const views = { register: renderRegister, login: renderLogin, user: renderProfile };

const app = document.getElementById('app');
const sessionBar = document.getElementById('session-bar');
const backendSelect = document.getElementById('backend-select');
const api = createApi(getBackend);

let navigationId = 0;

function navigate(hash) {
    location.hash = hash;
}

function decodeSegment(segment) {
    try {
        return decodeURIComponent(segment);
    } catch {
        return segment;
    }
}

/** Адрес вида #/user/<id>?query -> имя представления, сегменты пути и query-параметры. */
function parseLocation() {
    const [path, query = ''] = location.hash.replace(/^#\/?/, '').split('?');
    const [name = '', ...segments] = path.split('/');

    return { name, params: segments.map(decodeSegment), query: new URLSearchParams(query) };
}

function renderSession() {
    const session = getSession();

    if (session === null) {
        sessionBar.hidden = true;

        return;
    }

    sessionBar.replaceChildren(
        el('span', {}, `Вы вошли: ${session.userId}`),
        el('a', { class: 'button secondary', href: `#/user/${encodeURIComponent(session.userId)}` }, 'Моя анкета'),
        el('button', {
            type: 'button',
            class: 'secondary',
            onclick: () => {
                clearSession();
                renderSession();
                navigate('#/login');
            },
        }, 'Выйти'),
    );
    sessionBar.hidden = false;
}

function render() {
    const current = ++navigationId;
    const { name, params, query } = parseLocation();

    if (!Object.hasOwn(views, name)) {
        location.replace('#/register');

        return;
    }

    document.querySelectorAll('[data-route]').forEach((link) => {
        if (link.dataset.route === name) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });
    renderSession();
    app.replaceChildren(views[name]({
        api,
        params,
        query,
        navigate,
        isCurrent: () => current === navigationId,
        onSessionChange: renderSession,
    }));
}

backendSelect.replaceChildren(
    ...Object.entries(BACKENDS).map(([value, label]) => el('option', { value, selected: value === getBackend() }, label)),
);
backendSelect.addEventListener('change', () => {
    setBackend(backendSelect.value);
    // Токен выдан другим приложением: «вошли» должно относиться к выбранному backend'у.
    clearSession();
    render();
});

window.addEventListener('hashchange', render);
render();
