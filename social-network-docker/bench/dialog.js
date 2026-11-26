// Нагрузка на диалоги для k6: отправка (POST /dialog/{userId}/send) и чтение (GET /dialog/{userId}/list).
// Выбор диалога повторяет перекос сида: 10% запросов — горячий диалог, 20% — «Леди Гага», остальное — фон.
// MODE=rate (по умолчанию): постоянный поток запросов SEND_RPS и LIST_RPS в секунду, не зависящий от скорости ответов —
// так паузы и замедления во время решардинга видны, а не маскируются снижением нагрузки.
// MODE=max: VUS виртуальных пользователей без пауз (только отправка) — поиск пропускной способности.
// Каждый запрос пишется в лог строкой «REQ <мс окончания> <send|list> <статус> <длительность мс> <метка|->».
// Метка — текст отправленного сообщения (load:<RUN>:<номер>): по ней потом проверяется, что подтверждённые сообщения есть в БД.
// Параметры через -e: BASE_URL, RUN, DURATION, MODE, SEND_RPS, LIST_RPS, VUS, LOG (1 — писать строки REQ).
import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';
import exec from 'k6/execution';

function pairs(role) {
    return new SharedArray(role, () => open('/bench/dialog-users.csv')
        .split('\n')
        .filter((line) => line.startsWith(`${role},`))
        .map((line) => {
            const [, userId, token, companionId] = line.split(',');

            return { userId, token, companionId };
        }));
}

const hot = pairs('hot');
const gaga = pairs('gaga');
const background = pairs('background');

const mode = __ENV.MODE || 'rate';

function arrival(name, rate) {
    return {
        executor: 'constant-arrival-rate',
        exec: name,
        rate,
        timeUnit: '1s',
        duration: __ENV.DURATION,
        // Запас на паузу: запросы продолжают поступать, пока прежние ждут ответа. Все VU создаются заранее,
        // иначе k6 во время паузы пропускал бы запросы (dropped_iterations), пока добавляет новые.
        preAllocatedVUs: 600,
    };
}

export const options = {
    scenarios: mode === 'max'
        ? { send: { executor: 'constant-vus', exec: 'send', vus: Number(__ENV.VUS), duration: __ENV.DURATION } }
        : { send: arrival('send', Number(__ENV.SEND_RPS)), list: arrival('list', Number(__ENV.LIST_RPS)) },
    summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
    discardResponseBodies: true,
};

function pick() {
    const roll = Math.random();
    const source = roll < 0.1 ? hot : roll < 0.3 ? gaga : background;

    return source[Math.floor(Math.random() * source.length)];
}

function record(name, response, marker) {
    if (__ENV.LOG === '1') {
        console.log(`REQ ${Date.now()} ${name} ${response.status} ${response.timings.duration.toFixed(2)} ${marker}`);
    }
}

export function send() {
    const dialog = pick();
    const marker = `load:${__ENV.RUN}:${exec.scenario.iterationInTest}`;
    const response = http.post(
        `${__ENV.BASE_URL}/dialog/${dialog.companionId}/send`,
        JSON.stringify({ text: marker }),
        {
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${dialog.token}` },
            tags: { name: 'send' },
            timeout: '10s',
        },
    );

    record('send', response, marker);
    check(response, { 'send is 204': (r) => r.status === 204 });
}

export function list() {
    const dialog = pick();
    const response = http.get(`${__ENV.BASE_URL}/dialog/${dialog.companionId}/list?limit=50`, {
        headers: { Authorization: `Bearer ${dialog.token}` },
        tags: { name: 'list' },
        timeout: '10s',
    });

    record('list', response, '-');
    check(response, { 'list is 200': (r) => r.status === 200 });
}

export function handleSummary(data) {
    const requests = data.metrics.http_reqs.values;
    const duration = data.metrics.http_req_duration.values;
    const dropped = data.metrics.dropped_iterations ? data.metrics.dropped_iterations.values.count : 0;
    const line = `rps=${requests.rate.toFixed(1)} requests=${requests.count} dropped=${dropped}`
        + ` failed=${(data.metrics.http_req_failed.values.rate * 100).toFixed(3)}%`
        + ` p50=${duration.med.toFixed(1)}ms p95=${duration['p(95)'].toFixed(1)}ms`
        + ` p99=${duration['p(99)'].toFixed(1)}ms max=${duration.max.toFixed(1)}ms\n`;
    const output = { stdout: line };

    if (__ENV.SUMMARY) {
        output[__ENV.SUMMARY] = line;
    }

    return output;
}
