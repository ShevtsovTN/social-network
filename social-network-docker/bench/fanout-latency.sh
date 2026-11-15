#!/usr/bin/env bash
# Задержка fan-out: время от создания поста до его появления в ленте подписчика (см. fanout-latency.php).
# Стек поднят (make bench-up или make up, воркеры запущены), БД заполнена (make seed-feed).
# Авторы выбираются из БД по числу подписчиков: «малый» (около 10), «средний» (около 1000) и «знаменитость» (максимум).
# Параметры через окружение: ITERATIONS (20, постов на автора и приложение), APPS ("native symfony"), PHASE (если задан,
# таблица пишется ещё и в docs/homework-feed/results/<PHASE>/fanout-latency.md).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
iterations="${ITERATIONS:-20}"
apps="${APPS:-native symfony}"

[[ "$iterations" =~ ^[0-9]+$ ]] || { echo "ITERATIONS должен быть числом." >&2; exit 1; }

secret="${AUTH_TOKEN_SECRET:-}"
if [ -z "$secret" ] && [ -f "${root}/.env" ]; then
    secret="$(grep -E '^AUTH_TOKEN_SECRET=' "${root}/.env" | tail -n1 | cut -d= -f2-)"
fi
[ -n "$secret" ] || { echo "Не задан AUTH_TOKEN_SECRET (ни в окружении, ни в .env)." >&2; exit 1; }

declare -A ports=([native]="${NATIVE_PORT:-8081}" [symfony]="${SYMFONY_PORT:-8082}")
app_args=()
for app in $apps; do
    app_args+=("${app}=http://127.0.0.1:${ports[$app]}")
done

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Первый и последний подписчик по id: именно в таком порядке воркер обходит подписчиков автора порциями.
(cd "$root" && docker compose exec -T postgres sh -c 'psql -X -q -tA -F, -U "$POSTGRES_USER" -d "$POSTGRES_DB"') > "${work}/authors.csv" <<'SQL'
WITH followers AS (
    SELECT friend_id AS author_id, count(*) AS followers FROM friendships GROUP BY friend_id
), picked AS (
    SELECT 'малый' AS class, author_id, followers FROM (SELECT * FROM followers ORDER BY abs(followers - 10), random() LIMIT 1) AS small
    UNION ALL
    SELECT 'средний', author_id, followers FROM (SELECT * FROM followers ORDER BY abs(followers - 1000), random() LIMIT 1) AS medium
    UNION ALL
    SELECT 'знаменитость', author_id, followers FROM (SELECT * FROM followers ORDER BY followers DESC LIMIT 1) AS celebrity
)
SELECT class, author_id, followers,
       (SELECT user_id FROM friendships WHERE friend_id = picked.author_id ORDER BY user_id LIMIT 1),
       (SELECT user_id FROM friendships WHERE friend_id = picked.author_id ORDER BY user_id DESC LIMIT 1)
FROM picked;
SQL
sed -i '/^$/d' "${work}/authors.csv"

run() {
    docker run --rm --network host -e SECRET="$secret" -v "${here}:/bench:ro" -v "${work}:/work:ro" php:8.4.26-cli \
        php /bench/fanout-latency.php /work/authors.csv "$secret" "$iterations" "${app_args[@]}"
}

if [ -n "${PHASE:-}" ]; then
    results="${root}/docs/homework-feed/results/${PHASE}"
    mkdir -p "$results"
    run | tee "${results}/fanout-latency.md"
else
    run
fi
