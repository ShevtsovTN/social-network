import { saveSession } from '../session.js';
import { el } from '../dom.js';
import { createField, createSummary, handleSubmit } from '../form.js';

export function renderLogin({ api, query, navigate, onSessionChange }) {
    const fields = {
        userId: createField('userId', 'Идентификатор пользователя', el('input', {
            type: 'text',
            required: true,
            autocomplete: 'username',
            spellcheck: 'false',
            value: query.get('userId') ?? '',
        })),
        password: createField('password', 'Пароль', el('input', { type: 'password', maxlength: 128, required: true, autocomplete: 'current-password' })),
    };
    const summary = createSummary();
    const button = el('button', { type: 'submit' }, 'Войти');
    const form = el('form', { class: 'card form' },
        el('h2', {}, 'Вход'),
        Object.values(fields).map((field) => field.root),
        summary.root,
        button,
    );

    handleSubmit(form, button, { fields, summary }, async () => {
        const userId = fields.userId.control.value.trim();
        const { token } = await api.login(userId, fields.password.control.value);

        saveSession(userId, token);
        onSessionChange();
        navigate(`#/user/${encodeURIComponent(userId)}`);
    });

    return form;
}
