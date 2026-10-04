# symfony: Symfony-приложение

## Роль
Presentation и Infrastructure поверх `core`. Бизнес-логики здесь нет.

## Состав
- `public/index.php`: front controller (без symfony/runtime и dotenv, переменные берутся из окружения контейнера).
- `bin/console`: консоль, нужна в том числе для `cache:warmup` при сборке bench-образа.
- `src/Kernel.php`: ядро на `MicroKernelTrait`. Кэш и логи пишет в `APP_VAR_DIR`.
- `config/`: `bundles.php`, `services.yaml`, `routes.yaml` (атрибуты из `Presentation/Http`), `packages/`.
- `src/Infrastructure/Persistence/`: `PdoUserRepository`, фабрика PDO-подключения.
- `src/Infrastructure/Security/`: хешер на argon2id, выпуск и проверка токенов.
- `src/Presentation/Http/`: подкаталоги по ответственности: `Controller/`, `Request/` (`JsonBody`, `MalformedRequest`),
  `Response/` (`ApiJsonResponse`), `Routing/` (`StrictRoutingListener`), `Error/` (`ApiExceptionListener`).

## Правила
- Минимальный набор компонентов: http-kernel, routing, dependency-injection
  и то, без чего нельзя обойтись. Лишние бандлы не подключать.
- Без Doctrine и любого ORM. Только PDO с prepared statements.
- Infrastructure реализует порты из `core` и не содержит бизнес-правил.
  Реализации по подходу эквивалентны `apps/native`.
- Сервисы `core` и свои реализации портов регистрируем в `services.yaml` явно,
  привязывая интерфейсы к реализациям.
- Атрибуты и классы Symfony допустимы только в Infrastructure и Presentation.
- Контроллеры тонкие: разобрать запрос, вызвать handler, вернуть ответ.
- Формат ответов и коды ошибок строго по `docs/openapi.json`, идентично `apps/native`.
- Интеграционные тесты наследуют contract-тесты из `core`.
- Для замеров: `APP_ENV=prod`, `APP_DEBUG=0`, прогретый кэш,
  `composer dump-autoload --classmap-authoritative`.
- Не включать debug-тулбар, профайлер и dev-пакеты в рабочий образ.
- Namespace приложения: `SocialNetwork\SymfonyApp\`.
- Кэш и логи пишутся в `APP_VAR_DIR` (`/var/www/symfony-var` в контейнере, владелец `www-data`),
  а не в `apps/symfony/var`: так нет проблем с правами на bind mount.
- Консольные команды в запущенном контейнере выполнять от `www-data` (`make console c="..."`
  или `exec -u www-data`). От root они создают в `APP_VAR_DIR` файлы, которые php-fpm не может перезаписать.
- Окружения: dev (`APP_ENV=dev`, `APP_DEBUG=1`, через `docker-compose.override.yaml`)
  и prod для bench (`APP_ENV=prod`, `APP_DEBUG=0`, кэш прогревается при сборке образа).
- Минимальный набор зависимостей: `framework-bundle`, `console`, `yaml`. Новые добавлять только по согласованию.
- Версия Symfony: все компоненты одной линии 8.1 (`^8.1` для трёх прямых зависимостей). Не смешивать с 7.4: бандл 7.4 допускает компоненты 8.x,
  и без явного ограничения composer собирает гибрид, на котором нельзя честно мерить.
- `composer.lock` коммитится в репозиторий: bench-сборка должна быть воспроизводимой.