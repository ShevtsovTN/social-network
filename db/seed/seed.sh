#!/usr/bin/env bash
# Заполняет пустую таблицу users миллионом анкет из people.v2.csv (стек должен быть поднят).
# Путь к CSV можно передать первым аргументом.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
csv="${1:-${here}/people.v2.csv}"

[ -f "$csv" ] || { echo "Нет файла ${csv}: положите people.v2.csv в db/seed/." >&2; exit 1; }

# Файл бывает обрезан на середине строки: последняя строка без перевода строки неполная, отбрасываем её.
complete_lines=(cat)
if [ -n "$(tail -c 1 "$csv")" ]; then
    complete_lines=(sed '$d')
    echo "Последняя строка ${csv} неполная и будет пропущена." >&2
fi

"${complete_lines[@]}" "$csv" | docker compose exec -T postgres sh -c \
    'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /seed/load.sql'
