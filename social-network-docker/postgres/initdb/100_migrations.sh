#!/bin/sh
# Применяет SQL-миграции из db/migrations (смонтированы в /migrations) по порядку имён файлов.
# Выполняется один раз, при инициализации пустого тома primary; на реплики миграции приходят через репликацию.
set -eu

for migration in /migrations/*.sql; do
    echo "Применяется миграция ${migration}"
    psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f "$migration"
done
