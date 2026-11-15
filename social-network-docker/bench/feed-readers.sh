#!/usr/bin/env bash
# Выгружает случайных активных пользователей с токенами для нагрузки на ленту постов (feed-readers.csv: «id,token»).
# Нужен стек с заполненной лентой (make seed-feed). Токены считаются тем же HMAC, что в обоих приложениях
# (v1.<id>.<hmac-sha256 от id>, секрет AUTH_TOKEN_SECRET из окружения или .env): логин через argon2 для десятков тысяч
# пользователей занял бы часы. id при каждом сидировании новые, поэтому файл в git не хранится.
# READERS (по умолчанию 20000) — сколько пользователей выгрузить.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
output="${here}/feed-readers.csv"
count="${READERS:-20000}"

[[ "$count" =~ ^[0-9]+$ ]] || { echo "READERS должен быть числом." >&2; exit 1; }

secret="${AUTH_TOKEN_SECRET:-}"
if [ -z "$secret" ] && [ -f "${root}/.env" ]; then
    secret="$(grep -E '^AUTH_TOKEN_SECRET=' "${root}/.env" | tail -n1 | cut -d= -f2-)"
fi
[ -n "$secret" ] || { echo "Не задан AUTH_TOKEN_SECRET (ни в окружении, ни в .env)." >&2; exit 1; }

# Читатели — пользователи, у которых есть друзья: их лента не пуста. Порядок случайный.
(cd "$root" && docker compose exec -T postgres sh -c 'psql -X -q -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB"') <<SQL \
    | sed '/^$/d' \
    | docker run --rm -i -e SECRET="$secret" php:8.4.26-cli php -r '
        $secret = getenv("SECRET");
        while (($line = fgets(STDIN)) !== false) {
            $id = trim($line);
            if ($id !== "") {
                echo $id, ",v1.", $id, ".", hash_hmac("sha256", $id, $secret), "\n";
            }
        }' > "$output"
SELECT user_id FROM (SELECT DISTINCT user_id FROM friendships) AS readers ORDER BY random() LIMIT ${count};
SQL

echo "$(wc -l < "$output") читателей записано в ${output}"
