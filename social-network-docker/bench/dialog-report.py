"""Разбор прогона решардинга: что происходило с запросами на каждом этапе и все ли подтверждённые сообщения в БД.

Вход (в каталоге результатов): requests.log (строки REQ из dialog.js), events.csv («мс,событие»),
markers.txt (тексты сообщений этого прогона, найденные в БД).
Выход: timeline.csv (по секундам) и summary.md (по этапам и сверка сообщений).
"""

import csv
import sys
from collections import Counter, defaultdict
from pathlib import Path

results = Path(sys.argv[1])
SUCCESS = {'send': 204, 'list': 200}

# Этапы задаются парами событий: от первого до второго. Третье поле — этап с изменением кластера: для него берутся все
# запросы, которые хоть частью пришлись на этап (задержанный запрос может закончиться уже после операции). Для пауз
# берутся только запросы, целиком лежащие внутри паузы.
PHASES = [
    ('до изменений', 'load_start', 'add_node_start', False),
    ('добавление третьего узла', 'add_node_start', 'add_node_end', True),
    ('ребалансировка', 'rebalance_start', 'rebalance_end', True),
    ('пауза после ребалансировки', 'rebalance_end', 'isolate_start', False),
    ('изоляция горячего диалога', 'isolate_start', 'isolate_end', True),
    ('пауза после изоляции', 'isolate_end', 'add_hot_node_start', False),
    ('добавление узла горячего диалога', 'add_hot_node_start', 'add_hot_node_end', True),
    ('пауза перед переносом', 'add_hot_node_end', 'move_start', False),
    ('перенос горячего шарда', 'move_start', 'move_end', True),
    ('после изменений', 'move_end', 'load_end', False),
]

events = {}
with open(results / 'events.csv', encoding='utf-8') as source:
    for moment, name in csv.reader(source):
        events[name] = int(moment)

requests = []
with open(results / 'requests.log', encoding='utf-8') as source:
    for line in source:
        parts = line.split()
        if len(parts) == 6 and parts[0] == 'REQ':
            finished, name, status, duration, marker = int(parts[1]), parts[2], int(parts[3]), float(parts[4]), parts[5]
            requests.append((finished, name, status, duration, marker))
requests.sort()


def percentile(ordered, share):
    """Перцентиль по ближайшему рангу."""
    if not ordered:
        return float('nan')

    rank = max(1, -(-len(ordered) * share // 100))

    return ordered[int(rank) - 1]


def stats(rows, name):
    durations = sorted(row[3] for row in rows if row[1] == name)
    errors = sum(1 for row in rows if row[1] == name and row[2] != SUCCESS[name])

    return len(durations), errors, [percentile(durations, p) for p in (50, 95, 99)], (durations[-1] if durations else float('nan'))


def longest_gap(rows, start, end):
    """Самый длинный промежуток без единой подтверждённой отправки, задевающий этап, мс."""
    moments = [row[0] for row in rows if row[1] == 'send' and row[2] == SUCCESS['send']]

    return max((later - earlier for earlier, later in zip(moments, moments[1:]) if earlier < end and later > start), default=0)


origin = events['load_start']
buckets = defaultdict(list)
for row in requests:
    buckets[(row[0] - origin) // 1000].append(row)

with open(results / 'timeline.csv', 'w', newline='', encoding='utf-8') as target:
    writer = csv.writer(target)
    writer.writerow(['second', 'request', 'count', 'errors', 'p50_ms', 'p95_ms', 'p99_ms', 'max_ms'])
    for second in sorted(buckets):
        for name in ('send', 'list'):
            count, errors, quantiles, worst = stats(buckets[second], name)
            if count:
                writer.writerow([second, name, count, errors, *(f'{q:.1f}' for q in quantiles), f'{worst:.1f}'])

lines = ['| Этап | Секунды | Запрос | Запросов | Ошибок | p50, мс | p95, мс | p99, мс | max, мс | Без успешных отправок, мс |',
         '|---|---|---|---|---|---|---|---|---|---|']
for title, first, last, is_change in PHASES:
    if first not in events or last not in events:
        continue

    start, end = events[first], events[last]
    if is_change:
        rows = [row for row in requests if row[0] >= start and row[0] - row[3] < end]
    else:
        rows = [row for row in requests if row[0] - row[3] >= start and row[0] < end]
    span = f'{(start - origin) / 1000:.0f}–{(end - origin) / 1000:.0f}'
    for name in ('send', 'list'):
        count, errors, quantiles, worst = stats(rows, name)
        gap = str(longest_gap(requests, start, end)) if name == 'send' else ''
        lines.append(f'| {title} | {span} | {name} | {count} | {errors} | '
                     + ' | '.join(f'{q:.1f}' for q in quantiles) + f' | {worst:.1f} | {gap} |')

acked = {row[4] for row in requests if row[1] == 'send' and row[2] == SUCCESS['send']}
sent = {row[4] for row in requests if row[1] == 'send'}
stored = Counter(line.strip() for line in open(results / 'markers.txt', encoding='utf-8') if line.strip())
statuses = Counter((row[1], row[2]) for row in requests)

lines += [
    '',
    'Ответы за весь прогон: ' + ', '.join(f'{name} {status}: {count}' for (name, status), count in sorted(statuses.items())),
    '',
    '| Сверка сообщений | Число |',
    '|---|---|',
    f'| Отправлено запросов | {len(sent)} |',
    f'| Подтверждено (204) | {len(acked)} |',
    f'| Найдено в БД | {sum(stored.values())} |',
    f'| Потеряно (подтверждено, но нет в БД) | {len(acked - stored.keys())} |',
    f'| В БД без подтверждения | {len(stored.keys() - acked)} |',
    f'| Дублей в БД | {sum(count - 1 for count in stored.values() if count > 1)} |',
]

(results / 'summary.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')
print('\n'.join(lines))
