#!/bin/sh
# Сборка кластера Citus и применение миграций диалогов. Выполняется сервисом dialogs-init при каждом запуске стека
# и повторяемо: уже зарегистрированные узлы и уже применённые миграции пропускаются.
# Подключение задаётся переменными libpq (PGHOST — координатор, PGUSER, PGPASSWORD, PGDATABASE).
# DIALOGS_WORKERS — имена воркеров начального состава через пробел. Воркер, добавляемый при решардинге, сюда
# не входит: он регистрируется отдельно, под нагрузкой.
set -eu

: "${PGHOST:?не задан PGHOST}"
: "${DIALOGS_WORKERS:?не задан DIALOGS_WORKERS}"

sql() { psql -X -q -v ON_ERROR_STOP=1 "$@"; }

# После решардинга в кластере есть воркеры из профиля reshard: обычный запуск их не поднимает, и запросы к их шардам
# завершаются ошибкой, а с ними и регистрация узлов ниже. Поэтому предупреждение выводится первым.
for node in $(sql -tA -c "SELECT nodename FROM pg_dist_node WHERE groupid <> 0 ORDER BY nodename"); do
    pg_isready -q -t 5 -h "$node" -p 5432 \
        || echo "ВНИМАНИЕ: узел ${node} зарегистрирован в кластере, но не отвечает. Поднимите его:" \
                "docker compose --profile reshard up -d ${node}" >&2
done

# Под этим именем воркеры обращаются к координатору (без него Citus записал бы localhost).
echo "SELECT citus_set_coordinator_host(:'host', 5432);" | sql -v host="$PGHOST" -o /dev/null

for worker in $DIALOGS_WORKERS; do
    echo "Регистрируется воркер ${worker}"
    echo "SELECT citus_add_node(:'host', 5432);" | sql -v host="$worker" -o /dev/null
done

sql -c "SET client_min_messages = warning;
        CREATE TABLE IF NOT EXISTS schema_migrations (
            name       text        PRIMARY KEY,
            applied_at timestamptz NOT NULL DEFAULT now()
        )"

# Миграции применяются здесь, а не при инициализации тома: create_distributed_table раскладывает шарды по воркерам,
# которые к этому моменту должны быть зарегистрированы. Файл и отметка о нём фиксируются одной транзакцией.
for migration in /migrations/*.sql; do
    name="$(basename "$migration")"
    applied="$(echo "SELECT count(*) FROM schema_migrations WHERE name = :'name';" | sql -tA -v name="$name")"
    [ "$applied" = "0" ] || continue

    echo "Применяется миграция ${name}"
    echo "INSERT INTO schema_migrations (name) VALUES (:'name');" \
        | sql --single-transaction -o /dev/null -v name="$name" -f "$migration" -f -
done

echo "Кластер диалогов готов"
