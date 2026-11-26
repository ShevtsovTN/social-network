COMPOSE := docker compose

.DEFAULT_GOAL := help
.PHONY: help init up down debug-on debug-off build logs ps install test-core test-native test-symfony console bench-up bench-down bench-get compare reset-db seed monitoring-up monitoring-down repl-rebuild repl-failover repl-status repl-sync repl-async bench-write bench-search-queries bench-search bench-get-ids bench-read explain-feed bench-plot redis-cli redis-flush worker-restart worker-logs compare-infrastructure seed-posts-file seed-feed reset-feed bench-feed-readers bench-feed bench-fanout

help: ## Список команд
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-12s %s\n", $$1, $$2}'

init: ## Создать .env из .env.example (если его ещё нет)
	@test -f .env || cp .env.example .env
	@echo ".env готов. Поменяйте пароли и секреты перед использованием."

up: ## Запустить стек в режиме разработки (bind mount, target dev)
	$(COMPOSE) up -d --build

down: ## Остановить стек
	$(COMPOSE) down

debug-on: ## Включить Xdebug (mode=debug), пересоздаёт php и nginx
	XDEBUG_MODE=debug $(COMPOSE) up -d --force-recreate --no-deps php-native php-symfony nginx-native nginx-symfony

debug-off: ## Выключить Xdebug (mode=off), пересоздаёт php и nginx
	XDEBUG_MODE=off $(COMPOSE) up -d --force-recreate --no-deps php-native php-symfony nginx-native nginx-symfony

build: ## Пересобрать образы
	$(COMPOSE) build

logs: ## Логи всех сервисов
	$(COMPOSE) logs -f --tail=100

ps: ## Состояние контейнеров
	$(COMPOSE) ps

install: ## composer install для core и обоих приложений
	$(COMPOSE) run --rm --no-deps -w /app/packages/core php-native composer install
	$(COMPOSE) run --rm --no-deps php-native composer install
	$(COMPOSE) run --rm --no-deps php-symfony composer install

test-core: ## Unit-тесты ядра
	$(COMPOSE) run --rm --no-deps -w /app/packages/core php-native vendor/bin/phpunit

test-native: ## Интеграционные тесты native (нужен запущенный postgres)
	$(COMPOSE) run --rm php-native vendor/bin/phpunit

test-symfony: ## Интеграционные тесты symfony (нужен запущенный postgres)
	$(COMPOSE) run --rm php-symfony vendor/bin/phpunit

console: ## Консоль Symfony от www-data: make console c="debug:router"
	$(COMPOSE) exec -u www-data php-symfony php bin/console $(c)

bench-up: ## Стек для замеров (target bench, без bind mount, без frontend)
	PHP_BUILD_TARGET=bench $(COMPOSE) -f docker-compose.yaml up -d --build --remove-orphans

bench-down: ## Остановить стек замеров
	PHP_BUILD_TARGET=bench $(COMPOSE) -f docker-compose.yaml down

bench-get: ## Замер GET /user/get/{id} в обоих приложениях (после make bench-up): make bench-get CONCURRENCY=32 DURATION=15
	./social-network-docker/bench/get.sh

bench-search-queries: ## Сгенерировать набор префиксов для замера поиска (search-queries.csv, нужна заполненная БД)
	./social-network-docker/bench/search-queries.sh

bench-search: ## Замер GET /user/search через k6 (после make bench-up и make seed): make bench-search PHASE=before|after
	PHASE=$(PHASE) ./social-network-docker/bench/search.sh

bench-get-ids: ## Выгрузить случайные id анкет для замера GET /user/get/{id} (get-ids.csv, нужна заполненная БД)
	./social-network-docker/bench/get-ids.sh

bench-read: ## Замер чтения (get и search, k6) для ДЗ «Репликация»: make bench-read PHASE=baseline|replicas [SCENARIOS="get search"] [LEVELS="1 10 100 1000"]
	PHASE=$(PHASE) ./social-network-docker/bench/read.sh

bench-feed-readers: ## Выгрузить читателей ленты с токенами для нагрузки (feed-readers.csv, нужна заполненная лента): make bench-feed-readers [READERS=100000]
	./social-network-docker/bench/feed-readers.sh

bench-feed: ## Замер ленты постов (k6): make bench-feed PHASE=имя [MODES="nocache cold warm post"] [LEVELS="1 10 50 100"] [DURATION=30s] (после make bench-up, make seed-feed и make bench-feed-readers)
	PHASE=$(PHASE) ./social-network-docker/bench/feed.sh

bench-fanout: ## Задержка fan-out: от создания поста до появления в ленте подписчика, малый/средний автор и «знаменитость»: make bench-fanout [PHASE=имя] [ITERATIONS=20]
	./social-network-docker/bench/fanout-latency.sh

explain-feed: ## EXPLAIN (ANALYZE, BUFFERS) запросов ленты на реплике в docs/homework-feed/explain/<PHASE>.txt: make explain-feed PHASE=before-indexes
	@test -n "$(PHASE)" || { echo "Укажите PHASE, например PHASE=before-indexes" >&2; exit 1; }
	mkdir -p docs/homework-feed/explain
	$(COMPOSE) exec -T postgres-replica-1 sh -c 'psql -X -q -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -f -' \
		< social-network-docker/bench/explain-feed.sql | tee docs/homework-feed/explain/$(PHASE).txt

bench-plot: ## Графики и таблицы по итогам bench-search в docs/homework-indexes/
	docker build -q -t social-network-bench-plot social-network-docker/bench/plot >/dev/null
	docker run --rm --user "$$(id -u):$$(id -g)" -v "$(CURDIR)/docs/homework-indexes":/report social-network-bench-plot /report

monitoring-up: ## Мониторинг: Prometheus, postgres_exporter, redis_exporter, cAdvisor, Grafana (профиль monitoring; Grafana :3000, Prometheus :9090)
	$(COMPOSE) -f docker-compose.yaml --profile monitoring up -d prometheus grafana cadvisor redis-exporter postgres-exporter-primary postgres-exporter-replica-1 postgres-exporter-replica-2

monitoring-down: ## Остановить и удалить контейнеры мониторинга (данные Prometheus и Grafana в томах остаются)
	$(COMPOSE) -f docker-compose.yaml --profile monitoring rm -sf prometheus grafana cadvisor redis-exporter postgres-exporter-primary postgres-exporter-replica-1 postgres-exporter-replica-2

repl-rebuild: ## Пересобрать кластер PostgreSQL с нуля (тома БД удаляются, данные заливаются заново): нужно перед каждым failover
	./social-network-docker/replication/rebuild.sh

repl-failover: ## Эксперимент с аварией мастера: make repl-failover MODE=async|sync [NETEM_DELAY=300ms] [DURATION=40] [KILL_AFTER=15]
	./social-network-docker/replication/failover-experiment.sh $(MODE)

repl-status: ## Состояние репликации: роли узлов, подключённые реплики, лаг
	./social-network-docker/replication/status.sh

repl-sync: ## Кворумная синхронная репликация: ANY 1 (replica1, replica2)
	./social-network-docker/replication/sync-mode.sh sync

repl-async: ## Вернуть асинхронную репликацию
	./social-network-docker/replication/sync-mode.sh async

bench-write: ## Нагрузка на запись (POST /user/register, k6): make bench-write PHASE=name [APP=native] [VUS=16] [DURATION=30s]
	PHASE=$(PHASE) ./social-network-docker/bench/write.sh

compare: ## Сверка ответов native и symfony на одинаковых запросах, включая друзей, посты и ленту (стек и воркеры должны быть подняты)
	docker run --rm --network host -v "$(CURDIR)/social-network-docker/compare":/compare:ro php:8.4.26-cli \
		php /compare/compare.php http://127.0.0.1:$${NATIVE_PORT:-8081} http://127.0.0.1:$${SYMFONY_PORT:-8082}

compare-infrastructure: ## Сверка Infrastructure ленты постов в native и symfony (копии должны совпадать, иначе замеры нечестны)
	./social-network-docker/compare/infrastructure.sh

seed: ## Заполнить пустую таблицу users миллионом анкет из db/seed/people.v2.csv (стек должен быть поднят)
	./db/seed/seed.sh

redis-cli: ## Консоль Redis внутри контейнера: make redis-cli c="INFO memory"
	$(COMPOSE) exec redis redis-cli $(c)

redis-flush: ## Сбросить весь Redis (ленты, тела постов, стримы): холодный кэш для замеров; ленты пересоберутся из БД
	$(COMPOSE) exec redis redis-cli FLUSHALL

worker-restart: ## Перезапустить воркеры ленты (после правок кода в dev воркер держит старый код в памяти)
	$(COMPOSE) restart worker-native worker-symfony

worker-logs: ## Логи воркеров ленты
	$(COMPOSE) logs -f --tail=100 worker-native worker-symfony

seed-posts-file: ## Скачать posts.txt (тексты постов из ДЗ OTUS, около 6 МБ) в db/seed/ для make seed-feed (в git не хранится)
	curl -fsSL -o db/seed/posts.txt https://raw.githubusercontent.com/OtusTeam/highload/master/homework/posts.txt

seed-feed: ## Заполнить пустые friendships и posts (100 тыс. активных, около 2 млн друзей, 1 млн постов); нужны make seed и db/seed/posts.txt
	./db/seed/seed-feed.sh

reset-feed: ## Очистить friendships и posts и сбросить Redis (анкеты users остаются)
	$(COMPOSE) exec -T postgres sh -c 'psql -X -q -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -c "TRUNCATE friendships, posts"'
	$(COMPOSE) exec -T redis redis-cli FLUSHALL

reset-db: ## Остановить стек и удалить данные БД (миграции применятся заново при следующем up)
	$(COMPOSE) down -v