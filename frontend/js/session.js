export const BACKENDS = Object.freeze({
    native: 'native (чистый PHP)',
    symfony: 'Symfony',
});

const BACKEND_KEY = 'socialNetwork.backend';
const SESSION_KEY = 'socialNetwork.session';

let memorySession = null;

/** Хранилище браузера может быть недоступно (приватный режим), тогда работаем без него. */
function attempt(action) {
    try {
        return action();
    } catch {
        return null;
    }
}

export function getBackend() {
    const stored = attempt(() => localStorage.getItem(BACKEND_KEY));

    return stored !== null && Object.hasOwn(BACKENDS, stored) ? stored : 'native';
}

export function setBackend(backend) {
    attempt(() => localStorage.setItem(BACKEND_KEY, backend));
}

/**
 * Сессия: id пользователя и токен. Токен непрозрачный (формат определяет backend),
 * клиент его не разбирает. Пароль не сохраняется нигде.
 */
export function getSession() {
    if (memorySession !== null) {
        return memorySession;
    }

    const raw = attempt(() => sessionStorage.getItem(SESSION_KEY));
    const stored = raw === null ? null : attempt(() => JSON.parse(raw));

    if (typeof stored?.userId === 'string' && typeof stored?.token === 'string') {
        memorySession = stored;
    }

    return memorySession;
}

export function saveSession(userId, token) {
    memorySession = { userId, token };
    attempt(() => sessionStorage.setItem(SESSION_KEY, JSON.stringify(memorySession)));
}

export function clearSession() {
    memorySession = null;
    attempt(() => sessionStorage.removeItem(SESSION_KEY));
}
