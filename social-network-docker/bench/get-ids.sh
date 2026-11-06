#!/usr/bin/env bash
# Выгружает 10000 случайных id анкет для нагрузки GET /user/get/{id} (get-ids.csv).
# Нужен стек с заполненной БД (make seed). id при каждом сидировании новые, поэтому файл в git не хранится.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
output="${here}/get-ids.csv"

docker compose exec -T postgres sh -c 'psql -X -q -tA -U "$POSTGRES_USER" -d "$POSTGRES_DB"' > "$output" <<'SQL'
SELECT id FROM users ORDER BY random() LIMIT 10000;
SQL

sed -i '/^$/d' "$output"
echo "$(wc -l < "$output") id записано в ${output}"
