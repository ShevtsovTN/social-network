#!/usr/bin/env bash
# Пересобирает кластер PostgreSQL с нуля: удаляет тома primary и обеих реплик, поднимает стек в режиме bench,
# заливает миллион анкет (make seed) и ждёт, пока реплики догонят primary. Нужен перед каждым экспериментом
# с аварией: после failover прежний primary потерян, а кластер собран по-новому.
# Тома мониторинга (Prometheus, Grafana) не трогает. Данные БД будут удалены.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
cd "$root"

export PHP_BUILD_TARGET=bench
unset DB_PRIMARY_HOST
compose() { docker compose -f docker-compose.yaml "$@"; }

echo "Останавливаю приложения и узлы БД, удаляю их тома"
compose rm -sf php-native php-symfony nginx-native nginx-symfony haproxy postgres-replica-1 postgres-replica-2 postgres >/dev/null 2>&1 || true
for volume in pgdata pgdata-replica-1 pgdata-replica-2; do
    docker volume rm -f "social-network_${volume}" >/dev/null
done

echo "Поднимаю стек"
compose up -d --remove-orphans >/dev/null 2>&1

echo "Жду, пока реплики подключатся"
for _ in $(seq 1 60); do
    streaming="$(compose exec -T postgres sh -c 'psql -X -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM pg_stat_replication WHERE state = '"'"'streaming'"'"'"' 2>/dev/null || echo 0)"
    [ "$streaming" = "2" ] && break
    sleep 2
done
[ "$streaming" = "2" ] || { echo "Реплики не подключились" >&2; exit 1; }

echo "Заливаю данные"
"${root}/db/seed/seed.sh" >/dev/null

echo "Жду, пока реплики догонят primary"
for _ in $(seq 1 60); do
    lag="$(compose exec -T postgres sh -c 'psql -X -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT coalesce(max(pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn)), -1) FROM pg_stat_replication"')"
    [ "$lag" = "0" ] && break
    sleep 1
done
"${here}/status.sh" | sed -n '/pg_stat_replication/,/rows)/p'
echo "Кластер готов: primary postgres, реплики postgres-replica-1 и postgres-replica-2, режим async."
