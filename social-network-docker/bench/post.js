// Нагрузка на запись для k6: VUS виртуальных пользователей без пауз шлют POST /post/create от случайного автора
// из feed-readers.csv (все они активные пользователи, у многих есть подписчики). Каждый пост запускает в приложении
// публикацию события и асинхронный fan-out в воркере; задержку обработки очереди измеряет feed.sh после прогона.
// Параметры через -e: BASE_URL, VUS, DURATION, SUMMARY (путь к JSON-итогу; пусто — итог не пишется).
import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

const authors = new SharedArray('authors', () =>
    open('./feed-readers.csv')
        .split('\n')
        .filter((line) => line !== '')
        .map((line) => line.split(',')[1]),
);

const texts = [
    'Нагрузочный пост: короткий текст.',
    'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
    'Пост подлиннее. '.repeat(20),
    'Another load test post with some words in it.',
    'Эмодзи и юникод: 🙂 «ёлочки» и \\"кавычки\\".',
];

export const options = {
    scenarios: {
        post: {
            executor: 'constant-vus',
            vus: Number(__ENV.VUS),
            duration: __ENV.DURATION,
        },
    },
    discardResponseBodies: true,
    summaryTrendStats: ['avg', 'min', 'med', 'p(75)', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
    const token = authors[Math.floor(Math.random() * authors.length)];
    const body = JSON.stringify({ text: texts[Math.floor(Math.random() * texts.length)] });
    const response = http.post(`${__ENV.BASE_URL}/post/create`, body, {
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        timeout: '60s',
    });

    check(response, { 'status is 201': (r) => r.status === 201 });
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
