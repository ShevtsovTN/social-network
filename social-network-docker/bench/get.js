// Нагрузка на GET /user/get/{id} для k6: VUS виртуальных пользователей шлют запросы без пауз
// (замкнутая модель, как в search.js), id берутся из get-ids.csv — случайные анкеты из заполненной БД.
// Один и тот же id на все запросы не годится: он целиком оседает в кэше PostgreSQL и в буферах,
// а настоящие обращения расходятся по всей таблице.
// Параметры через -e: BASE_URL, VUS, DURATION, SUMMARY (путь к JSON-итогу; пусто — итог не пишется).
import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

const ids = new SharedArray('ids', () =>
    open('./get-ids.csv')
        .split('\n')
        .filter((line) => line !== ''),
);

export const options = {
    scenarios: {
        get: {
            executor: 'constant-vus',
            vus: Number(__ENV.VUS),
            duration: __ENV.DURATION,
        },
    },
    // Тело ответа не нужно: статус проверяется, разбор JSON в k6 исказил бы замер.
    discardResponseBodies: true,
    summaryTrendStats: ['avg', 'min', 'med', 'p(75)', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
    const id = ids[Math.floor(Math.random() * ids.length)];
    const response = http.get(`${__ENV.BASE_URL}/user/get/${id}`, { timeout: '60s' });

    check(response, { 'status is 200': (r) => r.status === 200 });
}

export function handleSummary(data) {
    const requests = data.metrics.http_reqs.values;
    const duration = data.metrics.http_req_duration.values;
    const line = `rps=${requests.rate.toFixed(1)} requests=${requests.count}`
        + ` failed=${(data.metrics.http_req_failed.values.rate * 100).toFixed(2)}%`
        + ` p50=${duration.med.toFixed(1)}ms p95=${duration['p(95)'].toFixed(1)}ms`
        + ` p99=${duration['p(99)'].toFixed(1)}ms max=${duration.max.toFixed(1)}ms\n`;
    const output = { stdout: line };

    if (__ENV.SUMMARY) {
        output[__ENV.SUMMARY] = JSON.stringify(data, null, 2);
    }

    return output;
}
