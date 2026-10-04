#!/usr/bin/env bash
# Замер GET /user/get/{id} против обоих приложений (стек должен быть поднят через `make bench-up`).
# Параметры через окружение: CONCURRENCY (по умолчанию 32), DURATION (15 с), WARMUP (3 с).
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
concurrency="${CONCURRENCY:-32}"
duration="${DURATION:-15}"
warmup="${WARMUP:-3}"

declare -A ports=([native]="${NATIVE_PORT:-8081}" [symfony]="${SYMFONY_PORT:-8082}")

for app in native symfony; do
    port="${ports[$app]}"
    response="$(curl -fsS -H 'Content-Type: application/json' "http://127.0.0.1:${port}/user/register" -d '{
        "firstName": "Bench", "lastName": "User", "birthDate": "1990-01-01",
        "gender": "male", "interests": ["chess"], "city": "Moscow", "password": "bench-password"
    }')"
    user_id="$(printf '%s' "$response" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')"
    [ -n "$user_id" ] || { echo "${app}: не удалось зарегистрировать пользователя: ${response}" >&2; exit 1; }

    url="http://127.0.0.1:${port}/user/get/${user_id}"
    run=(docker run --rm --network host -v "${here}:/bench:ro" php:8.4.26-cli php /bench/load.php "$url" "$concurrency")

    "${run[@]}" "$warmup" >/dev/null
    printf '%-8s' "${app}:"
    "${run[@]}" "$duration"
done
