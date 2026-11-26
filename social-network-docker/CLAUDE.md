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
  список id `get-ids.csv` (генерируется `make bench-get-ids`, в git не хранится), для ленты постов `feed.sh`/`feed.js`/`post.js`
  (`make bench-feed`; фаза nocache блокирует Redis сменой пароля, а не остановкой: остановленный контейнер даёт секунду на DNS),
  `fanout-latency.sh` (`make bench-fanout`) и `explain-feed.sql` (`make explain-feed`),
  образ для графиков `plot/` (matplotlib). Для диалогов (ДЗ «Шардинг диалогов»): `dialog.js` (k6: поток отправок и чтений
  с перекосом на горячий диалог и «Леди Гагу»), `dialog-users.sh` (`make bench-dialog-users`, `dialog-users.csv` не в git),
  `dialog-reshard.sh` (`make bench-dialog-reshard`: решардинг под нагрузкой, разбор в `dialog-report.py`), `dialog-skew.sh`
  и `.sql` (`make dialogs-skew`), `dialog-scale.sh` и `.sql` (`make bench-dialog-scale`: pgbench прямо на координатор при 2–4
  воркерах, отдельный проект compose `social-network-scale`, после замера удаляется). Пока идёт нагрузка, скрипт эксперимента читает только метаданные кластера: подсчёт строк
  в шардах сам портит задержки. В приложения не входят.
- `replication/`: операционные скрипты репликации: `status.sh` (`make repl-status`), `sync-mode.sh` (`make repl-sync`,
  `make repl-async`: кворумная синхронная репликация `ANY 1 (replica1, replica2)` и обратно). Штатный режим кластера: async.
  Эксперимент с аварией: `failover-experiment.sh` (`make repl-failover MODE=async|sync`: нагрузка на запись, kill -9 primary,
  выбор самой свежей реплики, promote, переключение второй реплики и приложений, подсчёт потерь), `netem.sh` (задержка
  сети primary → реплики через tc netem), `rebuild.sh` (`make repl-rebuild`: кластер с нуля; обязателен перед каждым
  экспериментом, потому что прежний primary после аварии потерян). Эксперимент разрушает primary и тома БД.
- `netem/`: образ с tc (iproute2) для netem; запускается рядом с узлом (`--net container:...`, `NET_ADMIN`), только для экспериментов.
- `haproxy/`: `haproxy.cfg` для балансировки чтения между репликами (tcp, roundrobin, `pgsql-check`; статистика и `/metrics` на :8404).
- `redis/`: `redis.conf` (ДЗ «Лента постов»): без персистентности (`save ""`, `appendonly no`), `maxmemory-policy volatile-lru`.
  `maxmemory` и пароль задаются в compose (`REDIS_MAXMEMORY`, `REDIS_PASSWORD`), лимиты контейнера `REDIS_CPUS`/`REDIS_MEMORY`.
  Один сервис `redis` на оба приложения; ключи, стрим и consumer group разделяет `REDIS_KEY_PREFIX` (`native`, `symfony`).
  `make redis-cli`, `make redis-flush`. Воркеры ленты `worker-native` (`php bin/feed-worker.php`) и `worker-symfony`
  (`php bin/console feed:work`, `user: www-data`: консоль от root оставила бы в `APP_VAR_DIR` файлы, которые php-fpm не перезапишет)
  на образе и коде приложения: `init: true`, `restart: unless-stopped`, лимиты 1 CPU и 256 МБ у обоих; входят в `make up` и
  `make bench-up`; `make worker-restart`, `make worker-logs`. В dev воркер Symfony работает с `APP_ENV=dev`, как `php-symfony`.
- `monitoring/`: `prometheus.yml` и провижининг Grafana (datasource, дашборды в `grafana/dashboards/`). Сервисы
  (Prometheus, postgres_exporter ×3, redis_exporter, cAdvisor, Grafana) в compose-профиле `monitoring`: `make monitoring-up`; в замеры и
  в обычный `make up` не входят. postgres_exporter ходит под ролью `exporter` (`pg_monitor`, `010_monitoring.sh`), redis_exporter
  основным паролем Redis; стримы событий для него перечислены в compose (`REDIS_EXPORTER_CHECK_SINGLE_STREAMS`, имена
  `<REDIS_KEY_PREFIX>:feed-events`: при смене префиксов поправить). Панель ленты: `grafana/dashboards/feed-redis.json`.
  Пока `make bench-feed` держит фазу nocache (пароль Redis сменён), экспортёр не может войти, и метрики Redis пропадают.
- `citus/` (ДЗ «Шардинг диалогов»): кластер Citus для сообщений диалогов, отдельный от основного PostgreSQL.
  `postgresql.conf` один на координатор и воркеры (`shared_preload_libraries = 'citus'`, `wal_level = logical` для переноса
  шардов под нагрузкой); `node-entrypoint.sh` пишет `.pgpass` из окружения (пароль для подключений между узлами; сами они идут
  по SSL: Citus включает его с самоподписанным сертификатом); `cluster-init.sh` (сервис `dialogs-init`, повторяемый) регистрирует
  воркеры и применяет `db/dialogs/migrations` с отметками в `schema_migrations` на координаторе; `status.sql`
  (`make dialogs-status`); `rebuild.sh` (`make dialogs-rebuild`: кластер с нуля и сид из `db/dialogs/seed`, удаляет все
  сообщения). Сервисы `dialogs-coordinator`, `dialogs-worker-1`, `dialogs-worker-2` входят в `make up` и
  `make bench-up`, `dialogs-worker-3` и `dialogs-worker-4` (узел под шард горячего диалога) только в профиле `reshard`. Лимиты: `DIALOGS_WORKER_*` одинаковы у всех воркеров,
  `DIALOGS_COORDINATOR_*` у координатора; на хосте открыт только координатор (`DIALOGS_POSTGRES_PORT`, 5440).
- `compare/`: `compare.php` (`make compare`, сверка HTTP-ответов native и symfony, включая друзей, посты, ленту и диалоги) и
  `infrastructure.sh` (`make compare-infrastructure`, сверка Infrastructure ленты и диалогов в двух копиях после нормализации namespace).
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
- Переключение приложений на нового мастера: `DB_PRIMARY_HOST=<узел>` и пересоздание php вместе с nginx (у нового контейнера
  php другой IP, а nginx держит адрес апстрима, разрешённый при старте, иначе 502).
- У php-контейнеров короткие таймауты резолвера (`dns_opt: timeout:1, attempts:1`): имя убитого контейнера Docker
  разрешает 5 секунд, libpq пробует дважды, и воркеры php-fpm висели бы 10 секунд на каждой попытке подключения
  к мёртвому primary (чтение через то же приложение вставало в очередь). Имена живых контейнеров разрешаются мгновенно.
- Redis не источник истины: данные ключей с TTL вытесняются, стрим без TTL ограничивается `MAXLEN ~` на стороне приложения.
  Клиент phpredis ставится в `base` образа PHP (`PHPREDIS_VERSION`), версия расширения закреплена.
- Версии базовых образов зафиксированы (без `latest`).
- Nginx не получает код приложений. Он только проксирует в php-fpm.
- Контейнеры не запускаются от root, если это не требуется для работы
  (master-процессы php-fpm и nginx работают от root, воркеры от `www-data` и `nginx`).
