# social-network-docker: контейнерная инфраструктура

## Состав
- `php/`: Dockerfile (targets `dev`, `bench`), `php.ini`, `opcache.ini`, `php-fpm.conf`.
- `nginx/`: Dockerfile, общий `nginx.conf`, server-блоки `native.conf` и `symfony.conf`;
  `frontend.conf` для dev-only `nginx-frontend` (статика и прокси, в bench не входит).
- `postgres/`: `postgresql.conf` с фиксированными параметрами для воспроизводимых замеров; один файл на все узлы,
  включая параметры репликации. `initdb/` (только primary, один раз на пустом томе): `000_replication.sh`
  (роль `replicator`, строка в `pg_hba.conf`) и `100_migrations.sh` (миграции из `db/migrations`).
  `replica-entrypoint.sh`: реплика на пустом томе снимает `pg_basebackup -R` с primary и запускается как standby.
  Лимиты CPU и памяти узлов БД задаются в compose (`POSTGRES_CPUS`, `POSTGRES_MEMORY`) и одинаковы у всех узлов.
- `bench/`: генераторы нагрузки (`load.php` для `make bench-get`, сценарии k6 `search.js` для `make bench-search`,
  `get.js`/`search.js` для `make bench-read`, `write.js` для `make bench-write`), набор запросов поиска `search-queries.csv`,
  список id `get-ids.csv` (генерируется `make bench-get-ids`, в git не хранится),
  образ для графиков `plot/` (matplotlib). В приложения не входят.
- `replication/`: операционные скрипты репликации: `status.sh` (`make repl-status`), `sync-mode.sh` (`make repl-sync`,
  `make repl-async`: кворумная синхронная репликация `ANY 1 (replica1, replica2)` и обратно). Штатный режим кластера: async.
- `haproxy/`: `haproxy.cfg` для балансировки чтения между репликами (tcp, roundrobin, `pgsql-check`; статистика и `/metrics` на :8404).
- `monitoring/`: `prometheus.yml` и провижининг Grafana (datasource, дашборды в `grafana/dashboards/`). Сервисы
  (Prometheus, postgres_exporter ×3, cAdvisor, Grafana) в compose-профиле `monitoring`: `make monitoring-up`; в замеры и
  в обычный `make up` не входят. Экспортёр ходит под ролью `exporter` (`pg_monitor`, `010_monitoring.sh`).
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
- Узлы PostgreSQL: `postgres` (primary), `postgres-replica-1`, `postgres-replica-2`. Реплики идентифицируются
  по `application_name` (`replica1`, `replica2`) в `primary_conninfo`; слоты репликации не используются.
  Пароль роли репликации только через окружение (`REPLICATION_PASSWORD`).
- Версии базовых образов зафиксированы (без `latest`).
- Nginx не получает код приложений. Он только проксирует в php-fpm.
- Контейнеры не запускаются от root, если это не требуется для работы
  (master-процессы php-fpm и nginx работают от root, воркеры от `www-data` и `nginx`).
