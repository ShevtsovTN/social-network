import { ApiError } from './api.js';
import { el } from './dom.js';

export const GENDER_LABELS = Object.freeze({
    male: 'Мужской',
    female: 'Женский',
    other: 'Другой',
});

/** Подсказки по полям в терминах домена (поле приходит в ответе 400 как `field`). */
const FIELD_HINTS = Object.freeze({
    firstName: 'Имя: от 1 до 100 символов.',
    lastName: 'Фамилия: от 1 до 100 символов.',
    birthDate: 'Дата рождения: формат ГГГГ-ММ-ДД, не раньше 1900-01-01 и не в будущем.',
    gender: 'Выберите пол из списка.',
    interests: 'Интересы: не более 20, каждый до 50 символов.',
    city: 'Город: от 1 до 100 символов.',
    password: 'Пароль: от 8 до 128 символов.',
    id: 'Идентификатор должен быть UUID.',
    body: 'Запрос не удалось разобрать как JSON.',
});

/**
 * Текст ошибки для пользователя плюс исходное сообщение сервера (для диагностики).
 * @returns {{text: string, detail: string}}
 */
export function describeFailure(error) {
    if (!(error instanceof ApiError)) {
        console.error(error);

        return { text: 'Непредвиденная ошибка в интерфейсе.', detail: '' };
    }

    const detail = error.message;

    if (error.status === 0) {
        return { text: 'Сервер недоступен. Проверьте, что стек запущен.', detail };
    }

    if (error.status === 400 && error.field !== null && Object.hasOwn(FIELD_HINTS, error.field)) {
        return { text: FIELD_HINTS[error.field], detail };
    }

    if (error.status === 401) {
        return { text: 'Неверный идентификатор или пароль.', detail };
    }

    if (error.status === 404) {
        return { text: 'Не найдено.', detail };
    }

    if (error.status >= 500) {
        return { text: 'Внутренняя ошибка сервера.', detail };
    }

    return { text: `Ошибка ${error.status}.`, detail };
}

function errorContent({ text, detail }) {
    return [text, detail === '' ? null : el('span', { class: 'detail' }, `Ответ сервера: ${detail}`)];
}

/** Поле формы: подпись, элемент ввода и место под сообщение об ошибке. */
export function createField(name, labelText, control) {
    control.name = name;
    control.id = `field-${name}`;

    const error = el('div', { class: 'field-error', role: 'alert', hidden: true });
    const root = el('label', { class: 'field' }, el('span', { class: 'field-label' }, labelText), control, error);

    return {
        root,
        control,
        showError(failure) {
            root.classList.add('invalid');
            error.replaceChildren(...errorContent(failure));
            error.hidden = false;
        },
        clearError() {
            root.classList.remove('invalid');
            error.hidden = true;
        },
    };
}

/** Общее сообщение об ошибке под формой (когда ошибку нельзя привязать к полю). */
export function createSummary() {
    const root = el('div', { class: 'summary', role: 'alert', hidden: true });

    return {
        root,
        show(failure) {
            root.replaceChildren(...errorContent(failure));
            root.hidden = false;
        },
        clear() {
            root.hidden = true;
        },
    };
}

/**
 * Подписывает форму на отправку: блокирует кнопку на время запроса и раскладывает ошибку
 * по полю (400 с известным `field`) или в общее сообщение.
 */
export function handleSubmit(form, button, { fields, summary }, action) {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (button.disabled) {
            return;
        }

        Object.values(fields).forEach((field) => field.clearError());
        summary.clear();
        button.disabled = true;

        try {
            await action();
        } catch (error) {
            const failure = describeFailure(error);
            const field = error instanceof ApiError && error.status === 400 ? fields[error.field] : undefined;

            if (field === undefined) {
                summary.show(failure);
            } else {
                field.showError(failure);
            }
        } finally {
            button.disabled = false;
        }
    });
}
