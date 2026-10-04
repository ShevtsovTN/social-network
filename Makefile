COMPOSE := docker compose

.DEFAULT_GOAL := help
.PHONY: help init up down debug-on debug-off build logs ps install test-core test-native test-symfony console bench-up bench-down bench-get compare reset-db

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

compare: ## Сверка ответов native и symfony на одинаковых запросах (стек должен быть поднят)
	docker run --rm --network host -v "$(CURDIR)/social-network-docker/compare":/compare:ro php:8.4.26-cli \
		php /compare/compare.php http://127.0.0.1:$${NATIVE_PORT:-8081} http://127.0.0.1:$${SYMFONY_PORT:-8082}

reset-db: ## Остановить стек и удалить данные БД (миграции применятся заново при следующем up)
	$(COMPOSE) down -v