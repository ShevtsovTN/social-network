import { el } from '../dom.js';
import { GENDER_LABELS, createField, createSummary, handleSubmit } from '../form.js';

export function renderRegister({ api }) {
    const today = new Date().toISOString().slice(0, 10);

    const fields = {
        firstName: createField('firstName', 'Имя', el('input', { type: 'text', maxlength: 100, required: true, autocomplete: 'given-name' })),
        lastName: createField('lastName', 'Фамилия', el('input', { type: 'text', maxlength: 100, required: true, autocomplete: 'family-name' })),
        birthDate: createField('birthDate', 'Дата рождения', el('input', { type: 'date', min: '1900-01-01', max: today, required: true })),
        gender: createField('gender', 'Пол', el('select', { required: true },
            el('option', { value: '' }, 'Выберите…'),
            Object.entries(GENDER_LABELS).map(([value, label]) => el('option', { value }, label)),
        )),
        interests: createField('interests', 'Интересы (по одному в строке)', el('textarea', { rows: 4 })),
        city: createField('city', 'Город', el('input', { type: 'text', maxlength: 100, required: true, autocomplete: 'address-level2' })),
        password: createField('password', 'Пароль', el('input', { type: 'password', minlength: 8, maxlength: 128, required: true, autocomplete: 'new-password' })),
    };
    const summary = createSummary();
    const button = el('button', { type: 'submit' }, 'Зарегистрироваться');
    const form = el('form', { class: 'card form' },
        el('h2', {}, 'Регистрация'),
        Object.values(fields).map((field) => field.root),
        summary.root,
        button,
    );
    const container = el('div', {}, form);

    handleSubmit(form, button, { fields, summary }, async () => {
        const interests = fields.interests.control.value.split(/\r?\n/).filter((line) => line.trim() !== '');

        const { id } = await api.register({
            firstName: fields.firstName.control.value,
            lastName: fields.lastName.control.value,
            birthDate: fields.birthDate.control.value,
            gender: fields.gender.control.value,
            interests,
            city: fields.city.control.value,
            password: fields.password.control.value,
        });

        container.replaceChildren(el('section', { class: 'card' },
            el('h2', {}, 'Пользователь создан'),
            el('p', {}, 'Ваш идентификатор. Он нужен для входа, сохраните его:'),
            el('code', { class: 'user-id' }, id),
            el('div', { class: 'actions' },
                el('a', { class: 'button', href: `#/login?userId=${encodeURIComponent(id)}` }, 'Войти'),
                el('a', { class: 'button secondary', href: `#/user/${encodeURIComponent(id)}` }, 'Открыть анкету'),
            ),
        ));
    });

    return container;
}
