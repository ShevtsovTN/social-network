// Нагрузка на GET /post/feed для k6. Читатели и токены берутся из feed-readers.csv (make bench-feed-readers).
// Режимы (MODE):
//   fill  — один проход по первым WARM_SIZE читателям (по разу каждого): лента каждого попадает в кэш;
//   cold  — читатели по очереди из среза [START, START + COUNT) без повторов: каждый запрос промах кэша и сборка ленты из БД;
//   warm  — случайные читатели из первых WARM_SIZE: после fill это попадания в кэш (при недоступном Redis — чтение из БД: «без кэша»).
// В режимах cold и warm VUS виртуальных пользователей шлют запросы без пауз (замкнутая модель, как в get.js);
// 90% запросов — первая страница (limit 10), остальные — случайная из первых десяти страниц.
// Параметры через -e: BASE_URL, MODE, VUS, DURATION, WARM_SIZE (2000), START (WARM_SIZE), COUNT (остаток списка), SUMMARY.
import http from 'k6/http';
import exec from 'k6/execution';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

const readers = new SharedArray('readers', () =>
    open('./feed-readers.csv')
        .split('\n')
        .filter((line) => line !== '')
        .map((line) => {
            const [id, token] = line.split(',');
            return { id, token };
        }),
);

const MODE = __ENV.MODE;
const WARM_SIZE = Number(__ENV.WARM_SIZE || 2000);
const START = Number(__ENV.START || WARM_SIZE);
const COUNT = Number(__ENV.COUNT || readers.length - START);

if (!['fill', 'cold', 'warm'].includes(MODE)) {
    throw new Error('MODE должен быть fill, cold или warm');
}
if (readers.length < WARM_SIZE) {
    throw new Error(`В feed-readers.csv ${readers.length} читателей, нужно не меньше ${WARM_SIZE}`);
}
if (MODE === 'cold' && COUNT <= 0) {
    throw new Error('В feed-readers.csv нет читателей для холодного режима: увеличьте READERS');
}

export const options = {
    scenarios: {
        feed: MODE === 'fill'
            ? { executor: 'shared-iterations', vus: 50, iterations: WARM_SIZE, maxDuration: '2h' }
            : { executor: 'constant-vus', vus: Number(__ENV.VUS), duration: __ENV.DURATION },
    },
    // Тело ответа не нужно: статус проверяется, разбор JSON в k6 исказил бы замер.
    discardResponseBodies: true,
    summaryTrendStats: ['avg', 'min', 'med', 'p(75)', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

export default function () {
    const iteration = exec.scenario.iterationInTest;
    let reader;

    if (MODE === 'fill') {
        reader = readers[iteration];
    } else if (MODE === 'cold') {
        reader = readers[START + (iteration % COUNT)];
    } else {
        reader = readers[Math.floor(Math.random() * WARM_SIZE)];
    }

    const offset = MODE !== 'fill' && Math.random() < 0.1 ? 10 * Math.floor(Math.random() * 10) : 0;
    const response = http.get(`${__ENV.BASE_URL}/post/feed?limit=10&offset=${offset}`, {
        headers: { Authorization: `Bearer ${reader.token}` },
        timeout: '60s',
    });

    check(response, { 'status is 200': (r) => r.status === 200 });
}

export function handleSummary(data) {
    const requests = data.metrics.http_reqs.values;
    const duration = data.metrics.http_req_duration.values;
    // В холодном режиме читатели не должны повторяться: иначе часть запросов уже попадания в кэш.
    const exhausted = MODE === 'cold' && requests.count > COUNT ? ` WARNING cold readers exhausted (${COUNT})` : '';
    const line = `rps=${requests.rate.toFixed(1)} requests=${requests.count}`
        + ` failed=${(data.metrics.http_req_failed.values.rate * 100).toFixed(2)}%`
        + ` p50=${duration.med.toFixed(1)}ms p95=${duration['p(95)'].toFixed(1)}ms`
        + ` p99=${duration['p(99)'].toFixed(1)}ms max=${duration.max.toFixed(1)}ms${exhausted}\n`;
    const output = { stdout: line };

    if (__ENV.SUMMARY) {
        output[__ENV.SUMMARY] = JSON.stringify(data, null, 2);
    }

    return output;
}
