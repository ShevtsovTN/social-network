import { el } from '../dom.js';
import { GENDER_LABELS, describeFailure } from '../form.js';

function userCard(user) {
    const interests = user.interests.length === 0
        ? '—'
        : el('ul', {}, user.interests.map((interest) => el('li', {}, interest)));

    return el('section', { class: 'card' },
        el('h2', {}, `${user.firstName} ${user.lastName}`),
        el('dl', { class: 'profile' },
            el('dt', {}, 'Дата рождения'), el('dd', {}, user.birthDate),
            el('dt', {}, 'Пол'), el('dd', {}, GENDER_LABELS[user.gender] ?? user.gender),
            el('dt', {}, 'Город'), el('dd', {}, user.city),
            el('dt', {}, 'Интересы'), el('dd', {}, interests),
            el('dt', {}, 'ID'), el('dd', {}, user.id),
        ),
    );
}

function failureBox(error) {
    const { text, detail } = describeFailure(error);

    return el('div', { class: 'summary', role: 'alert' }, text,
        detail === '' ? null : el('span', { class: 'detail' }, `Ответ сервера: ${detail}`));
}

export function renderProfile({ api, params, navigate, isCurrent }) {
    const requestedId = params[0] ?? '';
    const input = el('input', { type: 'text', required: true, placeholder: 'UUID пользователя', autocomplete: 'off', spellcheck: 'false', value: requestedId });
    const output = el('div');
    let latestLoad = 0;

    async function load(id) {
        const thisLoad = ++latestLoad;
        output.replaceChildren(el('p', { class: 'muted' }, 'Загрузка…'));

        try {
            const user = await api.getUser(id);

            if (isCurrent() && thisLoad === latestLoad) {
                output.replaceChildren(userCard(user));
            }
        } catch (error) {
            if (isCurrent() && thisLoad === latestLoad) {
                output.replaceChildren(failureBox(error));
            }
        }
    }

    const form = el('form', { class: 'card lookup' },
        el('label', { class: 'field' }, el('span', { class: 'field-label' }, 'Идентификатор пользователя'), input),
        el('button', { type: 'submit' }, 'Показать'),
    );

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const id = input.value.trim();

        if (id === '') {
            return;
        }

        // Тот же адрес не вызовет hashchange, поэтому перезагружаем вручную.
        if (id === requestedId) {
            load(id);
        } else {
            navigate(`#/user/${encodeURIComponent(id)}`);
        }
    });

    if (requestedId !== '') {
        load(requestedId);
    }

    return el('div', {}, form, output);
}
