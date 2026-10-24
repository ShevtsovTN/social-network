import { ApiError } from '../api.js';
import { el } from '../dom.js';
import { createField, createSummary, describeFailure } from '../form.js';

function resultList(users) {
    if (users.length === 0) {
        return el('p', { class: 'muted' }, 'Никого не найдено.');
    }

    return el('section', { class: 'card' },
        el('h2', {}, `Найдено: ${users.length}`),
        el('ol', { class: 'results' }, users.map((user) => el('li', {},
            el('a', { href: `#/user/${encodeURIComponent(user.id)}` }, `${user.firstName} ${user.lastName}`),
            el('span', { class: 'muted' }, ` · ${user.city} · ${user.birthDate}`),
        ))),
    );
}

export function renderSearch({ api, query, isCurrent }) {
    const fields = {
        firstName: createField('firstName', 'Начало имени', el('input', {
            type: 'text', required: true, maxlength: 100, autocomplete: 'off', placeholder: 'Конст', value: query.get('first_name') ?? '',
        })),
        lastName: createField('lastName', 'Начало фамилии', el('input', {
            type: 'text', required: true, maxlength: 100, autocomplete: 'off', placeholder: 'Оси', value: query.get('last_name') ?? '',
        })),
    };
    const summary = createSummary();
    const button = el('button', { type: 'submit' }, 'Найти');
    const output = el('div');
    const form = el('form', { class: 'card form' },
        el('h2', {}, 'Поиск анкет'),
        el('p', { class: 'muted' }, 'По началу имени и фамилии одновременно, с учётом регистра. Результат отсортирован по id.'),
        Object.values(fields).map((field) => field.root),
        summary.root,
        button,
    );
    let latestSearch = 0;

    async function search(firstName, lastName) {
        const thisSearch = ++latestSearch;
        Object.values(fields).forEach((field) => field.clearError());
        summary.clear();
        button.disabled = true;
        output.replaceChildren(el('p', { class: 'muted' }, 'Поиск…'));

        try {
            const users = await api.searchUsers(firstName, lastName);

            if (isCurrent() && thisSearch === latestSearch) {
                output.replaceChildren(resultList(users));
            }
        } catch (error) {
            if (!isCurrent() || thisSearch !== latestSearch) {
                return;
            }

            output.replaceChildren();
            const failure = describeFailure(error);
            const field = error instanceof ApiError && error.status === 400 ? fields[error.field] : undefined;

            if (field === undefined) {
                summary.show(failure);
            } else {
                field.showError(failure);
            }
        } finally {
            if (thisSearch === latestSearch) {
                button.disabled = false;
            }
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const firstName = fields.firstName.control.value;
        const lastName = fields.lastName.control.value;
        // Адрес с параметрами поиска можно сохранить или отправить; replaceState не вызывает hashchange и перерисовку.
        history.replaceState(null, '', `#/search?${new URLSearchParams({ first_name: firstName, last_name: lastName })}`);
        search(firstName, lastName);
    });

    if (query.has('first_name') && query.has('last_name')) {
        search(query.get('first_name'), query.get('last_name'));
    }

    return el('div', {}, form, output);
}
