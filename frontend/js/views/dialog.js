import { ApiError } from '../api.js';
import { el } from '../dom.js';
import { createField, createSummary, describeFailure, handleSubmit } from '../form.js';
import { getSession } from '../session.js';

const PAGE_SIZE = 50;

/** Ближе этого расстояния (в пикселях) до верха списка подгружается следующая страница истории. */
const LOAD_OLDER_THRESHOLD = 80;

const DIALOG_HINTS = Object.freeze({
    userId: 'Собеседник: UUID другого пользователя. Диалог с самим собой невозможен.',
    text: 'Сообщение: от 1 до 2000 символов, без управляющих символов, кроме перевода строки.',
});

function describeDialogFailure(error) {
    if (error instanceof ApiError) {
        if (error.status === 401) {
            return { text: 'Сессия недействительна: выйдите и войдите заново.', detail: error.message };
        }

        if (error.status === 404) {
            return { text: 'Пользователь не найден.', detail: error.message };
        }

        if (error.status === 400 && Object.hasOwn(DIALOG_HINTS, error.field)) {
            return { text: DIALOG_HINTS[error.field], detail: error.message };
        }
    }

    return describeFailure(error);
}

/** История диалога и форма отправки для выбранного собеседника. */
function conversation({ api, session, companionId, isCurrent }) {
    const ownId = session.userId.toLowerCase();
    let companionName = 'Собеседник';

    const title = el('h2', {}, 'Диалог');
    const status = el('div', { class: 'history-status muted', role: 'status' });
    const list = el('ol');
    const box = el('div', { class: 'messages', tabindex: 0, 'aria-label': 'Сообщения диалога' }, status, list);

    let oldestId;
    let hasOlder = false;
    let loading = false;
    let failed = false;
    // Номер загрузки диалога с начала: ответы на запросы, начатые до «Обновить», отбрасываются.
    let generation = 0;

    function item(message) {
        const own = message.from === ownId;

        return el('li', { class: own ? 'message own' : 'message', 'data-id': message.id },
            el('span', { class: 'message-author', 'data-own': own ? 'true' : 'false' }, own ? 'Вы' : companionName),
            el('span', { class: 'message-text' }, message.text),
        );
    }

    function showHistoryState() {
        if (hasOlder) {
            status.replaceChildren('Прокрутите вверх, чтобы загрузить более ранние сообщения.');
        } else {
            status.replaceChildren(list.childElementCount === 0 ? 'Сообщений пока нет.' : 'Начало переписки.');
        }
    }

    function showFailure(error, retry) {
        const { text, detail } = describeDialogFailure(error);
        // Повтор имеет смысл только при временной ошибке: неверный запрос повторный вызов не исправит.
        const temporary = !(error instanceof ApiError) || error.status === 0 || error.status >= 500;

        status.replaceChildren(
            el('span', { class: 'summary', role: 'alert' }, text,
                detail === '' ? null : el('span', { class: 'detail' }, `Ответ сервера: ${detail}`)),
            ...(temporary ? [el('button', { type: 'button', class: 'secondary', onclick: retry }, 'Повторить')] : []),
        );
    }

    /** Последняя страница: показывается с нуля и прокручивается к самому новому сообщению. */
    async function loadLatest() {
        const thisGeneration = ++generation;
        loading = true;
        failed = false;
        status.replaceChildren('Загрузка…');

        try {
            const page = await api.getDialog(session.token, companionId, { limit: PAGE_SIZE });

            if (!isCurrent() || thisGeneration !== generation) {
                return;
            }

            list.replaceChildren(...page.map(item));
            oldestId = page[0]?.id;
            hasOlder = page.length === PAGE_SIZE;
            loading = false;
            showHistoryState();
            box.scrollTop = box.scrollHeight;
            fillViewport();
        } catch (error) {
            if (isCurrent() && thisGeneration === generation) {
                loading = false;
                failed = true;
                showFailure(error, loadLatest);
            }
        }
    }

    /** Страница старее уже загруженной: добавляется сверху, видимые сообщения остаются на месте. */
    async function loadOlder() {
        if (loading || failed || !hasOlder) {
            return;
        }

        const thisGeneration = generation;
        loading = true;
        status.replaceChildren('Загрузка…');

        try {
            const page = await api.getDialog(session.token, companionId, { limit: PAGE_SIZE, before: oldestId });

            if (!isCurrent() || thisGeneration !== generation) {
                return;
            }

            const height = box.scrollHeight;
            const position = box.scrollTop;

            list.prepend(...page.map(item));
            oldestId = page[0]?.id ?? oldestId;
            // Страница короче запрошенной означает, что более старых сообщений нет.
            hasOlder = page.length === PAGE_SIZE;
            loading = false;
            showHistoryState();
            box.scrollTop = position + (box.scrollHeight - height);
            fillViewport();
        } catch (error) {
            if (isCurrent() && thisGeneration === generation) {
                loading = false;
                failed = true;
                showFailure(error, () => {
                    failed = false;
                    loadOlder();
                });
            }
        }
    }

    /** Пока список не прокручивается, события прокрутки не будет: историю надо догрузить самим. */
    function fillViewport() {
        if (box.isConnected && box.scrollHeight <= box.clientHeight) {
            loadOlder();
        }
    }

    box.addEventListener('scroll', () => {
        if (box.scrollTop < LOAD_OLDER_THRESHOLD) {
            loadOlder();
        }
    });

    const fields = {
        text: createField('text', 'Сообщение', el('textarea', { rows: 3, maxlength: 2000, required: true })),
    };
    const summary = createSummary();
    const button = el('button', { type: 'submit' }, 'Отправить');
    const form = el('form', { class: 'form compose' },
        fields.text.root,
        summary.root,
        el('div', { class: 'actions' },
            button,
            el('button', { type: 'button', class: 'secondary', onclick: () => loadLatest() }, 'Обновить'),
        ),
    );

    handleSubmit(form, button, { fields, summary, describe: describeDialogFailure }, async () => {
        await api.sendMessage(session.token, companionId, fields.text.control.value);

        if (isCurrent()) {
            fields.text.control.value = '';
            loadLatest();
        }
    });

    fields.text.control.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    // Имя собеседника только украшает экран: без него диалог работает так же.
    api.getUser(companionId).then((user) => {
        if (!isCurrent()) {
            return;
        }

        companionName = `${user.firstName} ${user.lastName}`;
        title.textContent = `Диалог: ${companionName}`;
        list.querySelectorAll('[data-own="false"]').forEach((author) => {
            author.textContent = companionName;
        });
    }).catch(() => {});

    loadLatest();

    return el('section', { class: 'card' }, title, box, form);
}

export function renderDialog({ api, params, navigate, isCurrent }) {
    const session = getSession();

    if (session === null) {
        return el('section', { class: 'card' },
            el('h2', {}, 'Диалог'),
            el('p', {}, 'Чтобы читать и отправлять сообщения, войдите.'),
            el('a', { class: 'button', href: '#/login' }, 'Войти'),
        );
    }

    const companionId = params[0] ?? '';

    // С выбранным собеседником форма выбора не показывается, чтобы поле ввода помещалось на экране;
    // другого собеседника открывают через вкладку «Диалог» или из его анкеты.
    if (companionId !== '') {
        return conversation({ api, session, companionId, isCurrent });
    }

    const input = el('input', { type: 'text', required: true, placeholder: 'UUID пользователя', autocomplete: 'off', spellcheck: 'false' });
    const form = el('form', { class: 'card lookup' },
        el('label', { class: 'field' }, el('span', { class: 'field-label' }, 'Идентификатор собеседника'), input),
        el('button', { type: 'submit' }, 'Открыть'),
    );

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const id = input.value.trim();

        if (id !== '') {
            navigate(`#/dialog/${encodeURIComponent(id)}`);
        }
    });

    return form;
}
