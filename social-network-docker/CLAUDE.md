# social-network-docker: контейнерная инфраструктура

## Состав
- `php/`: Dockerfile (targets `dev`, `bench`), `php.ini`, `opcache.ini`, `php-fpm.conf`.
- `nginx/`: Dockerfile, общий `nginx.conf`, server-блоки `native.conf` и `symfony.conf`;
  `frontend.conf` для dev-only `nginx-frontend` (статика и прокси, в bench не входит).
- `postgres/`: `postgresql.conf` с фиксированными параметрами для воспроизводимых замеров.
- `docker-compose.yaml`, `docker-compose.override.yaml` и `.env` лежат в корне репозитория, не здесь.

## Правила
- Один PHP-образ на оба приложения. Различия только в `working_dir` и коде.
- Контекст сборки PHP-образа: корень репозитория (target `bench` копирует код).
- Настройки PHP, php-fpm и nginx одинаковы для обоих приложений.
  Любое различие между `native` и `symfony` в конфигах должно быть
  обосновано и записано в README.
- Target `dev`: код через bind mount (`docker-compose.override.yaml`), opcache перечитывает файлы.
- Target `bench`: код копируется в образ, composer с `--no-dev` и
  `--classmap-authoritative`, opcache без проверки timestamps, xdebug отсутствует.
  Замеры проводятся только на этом target, без override-файла (`make bench-up`).
- Не копировать `.env` и секреты в образы. Конфигурация через переменные окружения.
- Версии базовых образов зафиксированы (без `latest`).
- Nginx не получает код приложений. Он только проксирует в php-fpm.
- Контейнеры не запускаются от root, если это не требуется для работы
  (master-процессы php-fpm и nginx работают от root, воркеры от `www-data` и `nginx`).
