// Нагрузка на запись для k6: VUS виртуальных пользователей без пауз шлют POST /user/register.
// Каждый подтверждённый ответ (201) клиент считает успешной записью: счётчик acked_registrations и строка
// «ACK <id>» в логе консоли. По этим id после аварии проверяется, какие подтверждённые записи есть на новом мастере.
// Неподтверждённые запросы (ошибка, таймаут, оборванное соединение) в счёт не идут: транзакция могла как
// закоммититься, так и нет, и потерей это не считается.
// Параметры через -e: BASE_URL, VUS, DURATION, TIMEOUT (по умолчанию 10s), SUMMARY (путь к JSON-итогу).
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const acked = new Counter('acked_registrations');

export const options = {
    scenarios: {
        write: {
            executor: 'constant-vus',
            vus: Number(__ENV.VUS),
            duration: __ENV.DURATION,
        },
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(75)', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const headers = { 'Content-Type': 'application/json' };

export default function () {
    const body = JSON.stringify({
        firstName: 'Load',
        lastName: 'Writer',
        birthDate: '1990-01-01',
        gender: 'male',
        interests: ['k6'],
        city: 'Moscow',
        password: 'load-password',
    });
    const response = http.post(`${__ENV.BASE_URL}/user/register`, body, {
        headers,
        timeout: __ENV.TIMEOUT || '10s',
    });

    if (response.status === 201) {
        acked.add(1);
        console.log(`ACK ${response.json('id')}`);
    }

    check(response, { 'status is 201': (r) => r.status === 201 });
}

export function handleSummary(data) {
    const requests = data.metrics.http_reqs.values;
    const duration = data.metrics.http_req_duration.values;
    const ackedCount = data.metrics.acked_registrations ? data.metrics.acked_registrations.values.count : 0;
    const line = `rps=${requests.rate.toFixed(1)} requests=${requests.count} acked=${ackedCount}`
        + ` failed=${(data.metrics.http_req_failed.values.rate * 100).toFixed(2)}%`
        + ` p50=${duration.med.toFixed(1)}ms p95=${duration['p(95)'].toFixed(1)}ms`
        + ` p99=${duration['p(99)'].toFixed(1)}ms max=${duration.max.toFixed(1)}ms\n`;
    const output = { stdout: line };

    if (__ENV.SUMMARY) {
        output[__ENV.SUMMARY] = JSON.stringify(data, null, 2);
    }

    return output;
}
