/**
 * Клиент HTTP-контракта из docs/openapi.json. Запросы идут на тот же origin:
 * nginx-frontend проксирует /api/<backend>/ в выбранное приложение.
 */
export class ApiError extends Error {
    /**
     * @param {number} status HTTP-статус; 0, если ответа не было вовсе
     * @param {string} message сообщение сервера (может быть пустым)
     * @param {string|null} field имя поля при ошибке валидации
     */
    constructor(status, message, field = null) {
        super(message);
        this.status = status;
        this.field = field;
    }
}

export function createApi(getBackend) {
    /** @returns разобранное тело ответа; null для 204 */
    async function request(method, path, body, token) {
        const headers = {};

        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        if (token !== undefined) {
            headers.Authorization = `Bearer ${token}`;
        }

        let response;

        try {
            response = await fetch(`/api/${getBackend()}${path}`, {
                method,
                headers,
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch {
            throw new ApiError(0, '');
        }

        if (response.status === 204) {
            return null;
        }

        let payload = null;

        try {
            payload = await response.json();
        } catch {
            // Не JSON (например, страница ошибки прокси): разберёмся по статусу.
        }

        if (!response.ok) {
            throw new ApiError(response.status, payload?.message ?? '', payload?.field ?? null);
        }

        if (payload === null) {
            throw new ApiError(response.status, '');
        }

        return payload;
    }

    return {
        /** @returns {Promise<{id: string}>} */
        register: (data) => request('POST', '/user/register', data),
        /** @returns {Promise<{token: string}>} */
        login: (userId, password) => request('POST', '/login', { userId, password }),
        getUser: (id) => request('GET', `/user/get/${encodeURIComponent(id)}`),
        /** @returns {Promise<Array<object>>} анкеты, отсортированные по id */
        searchUsers: (firstName, lastName) => request('GET', `/user/search?${new URLSearchParams({ first_name: firstName, last_name: lastName })}`),
        /** @returns {Promise<null>} */
        sendMessage: (token, userId, text) => request('POST', `/dialog/${encodeURIComponent(userId)}/send`, { text }, token),
        /**
         * Страница диалога: не больше limit самых новых сообщений, идущих раньше сообщения before.
         *
         * @returns {Promise<Array<{id: string, from: string, to: string, text: string}>>} от старых к новым
         */
        getDialog: (token, userId, { limit, before }) => {
            const query = new URLSearchParams({ limit: String(limit) });

            if (before !== undefined) {
                query.set('before', before);
            }

            return request('GET', `/dialog/${encodeURIComponent(userId)}/list?${query}`, undefined, token);
        },
    };
}
