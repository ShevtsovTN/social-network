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
    async function request(method, path, body) {
        let response;

        try {
            response = await fetch(`/api/${getBackend()}${path}`, {
                method,
                headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch {
            throw new ApiError(0, '');
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
    };
}
