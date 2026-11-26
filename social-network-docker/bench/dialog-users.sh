#!/usr/bin/env bash
# Выгружает пары участников диалогов с токенами для нагрузки (dialog-users.csv: «роль,id,token,id собеседника»).
# Нужен make seed-dialogs. Роли: background, gaga («Леди Гага» пишет своим собеседникам), hot (горячий диалог).
# Токены считаются тем же HMAC, что в обоих приложениях (см. feed-readers.sh). В git файл не хранится.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "${here}/../.." && pwd)"
output="${here}/dialog-users.csv"

secret="${AUTH_TOKEN_SECRET:-}"
if [ -z "$secret" ] && [ -f "${root}/.env" ]; then
    secret="$(grep -E '^AUTH_TOKEN_SECRET=' "${root}/.env" | tail -n1 | cut -d= -f2-)"
fi
[ -n "$secret" ] || { echo "Не задан AUTH_TOKEN_SECRET (ни в окружении, ни в .env)." >&2; exit 1; }

(cd "$root" && docker compose exec -T dialogs-coordinator sh -c \
    'psql -X -q -tA -F, -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT role, user_id, companion_id FROM seed_dialog_roles ORDER BY role, companion_id"') \
    | docker run --rm -i -e SECRET="$secret" php:8.4.26-cli php -r '
        $secret = getenv("SECRET");
        while (($line = fgets(STDIN)) !== false) {
            if (trim($line) === "") {
                continue;
            }
            [$role, $user, $companion] = explode(",", trim($line));
            echo $role, ",", $user, ",v1.", $user, ".", hash_hmac("sha256", $user, $secret), ",", $companion, "\n";
        }' > "$output"

echo "$(wc -l < "$output") пар записано в ${output}"
