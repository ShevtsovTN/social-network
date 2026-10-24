#!/usr/bin/env bash
# Генерирует набор запросов для нагрузки GET /user/search: 1000 пар «3 буквы имени, 3 буквы фамилии»
# из случайных анкет (популярные имена встречаются чаще, как в реальном поиске).
# Нужен стек с заполненной БД (make seed). Файл коммитится: до и после индекса гоняется один и тот же набор.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
output="${here}/search-queries.csv"

docker compose exec -T postgres sh -c 'psql -X -q -tA -F, -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > "$output" <<'SQL'
SELECT setseed(0.42);
SELECT left(first_name, 3), left(last_name, 3)
FROM (SELECT first_name, last_name FROM users ORDER BY first_name, last_name, birth_date, city, id) AS ordered
ORDER BY random()
LIMIT 1000;
SQL

# setseed печатает пустую строку, она не нужна.
sed -i '/^$/d' "$output"
echo "$(wc -l < "$output") пар записано в ${output}"
