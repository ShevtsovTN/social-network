# symfony: Symfony-приложение

## Роль
Presentation и Infrastructure поверх `core`. Бизнес-логики здесь нет.

## Состав
- `public/index.php`: front controller (без symfony/runtime и dotenv, переменные берутся из окружения контейнера).
- `bin/console`: консоль, нужна в том числе для `cache:warmup` при сборке bench-образа.
- `src/Kernel.php`: ядро на `MicroKernelTrait`. Кэш и логи пишет в `APP_VAR_DIR`.
- `config/`: `bundles.php`, `services.yaml`, `routes.yaml` (атрибуты из `Presentation/Http`), `packages/`.
- `src/Infrastructure/Persistence/`: `PdoUserRepository`, `PrimaryFallbackUserRepository` (чтение с реплики с возвратом к primary
  при промахе), фабрика PDO-подключения; для ДЗ «Лента постов»: `PdoPostRepository`, `PdoFriendshipRepository`, `PdoFeedSource`
  (копии классов `apps/native`, отличается только namespace: приложения не зависят друг от друга).
- `src/Infrastructure/Security/`: хешер на argon2id, выпуск и проверка токенов, `RandomPostIdGenerator` (UUIDv7).
- `src/Infrastructure/Redis/` и `src/Infrastructure/Queue/` (ДЗ «Лента постов»): `RedisConnection`, `RedisKeyspace`, `LuaScript`, `RedisFeedCache`,
  `RedisPostCache`, `FeedEventSerializer`, `RedisFeedEventPublisher`, `RedisFeedEventConsumer`. Копии классов `apps/native`
  (отличаются namespace и префиксом `[symfony]` в логах): те же Lua-скрипты, TTL, длина стрима, таймауты. Описание — в `apps/native/CLAUDE.md`.
  Меняя один из этих классов, меняйте оба приложения: нагрузочное сравнение честно только при одинаковом подходе.
- `src/Presentation/Console/FeedWorkerCommand`: команда `feed:work`, воркер ленты (цикл вокруг use case `ProcessNextFeedEvent`; при ошибке
  инфраструктуры завершается с кодом 1, перезапуск делает docker compose). Запускать от `www-data`. Сервисы воркера в `services.yaml`:
  `redis.connection.worker` (обычное подключение, таймаут чтения 10 с), `feed_cache.worker`, `post_cache.worker`, потребитель очереди
  (имя из `HOSTNAME`), `ApplyFeedEventHandler` на primary.
- `src/Presentation/Http/`: подкаталоги по ответственности: `Controller/`, `Request/` (`JsonBody`, `QueryParameters`, `MalformedRequest`),
  `Response/` (`ApiJsonResponse`, `NoContentResponse` для 204, `UserPayload`, `PostPayload`), `Routing/` (`StrictRoutingListener`),
  `Error/` (`ApiExceptionListener`), `Auth/` (`RequestAuthenticator`: токен из `Authorization: Bearer` в use case `Authenticate`;
  разбор заголовка — `Request/BearerToken`). Контроллеры друзей, постов и ленты тонкие, как остальные: аутентификация, разбор запроса,
  вызов use case, ответ; `offset` и `limit` ленты передаются в `core` строками как пришли.

## Правила
- Минимальный набор компонентов: http-kernel, routing, dependency-injection
  и то, без чего нельзя обойтись. Лишние бандлы не подключать.
- Маршрутизация чтения и записи (ДЗ «Репликация»), решение принимается в composition root (`config/services.yaml`), не в репозитории:
  два PDO (`DB_HOST` primary, `DB_READ_HOST` реплики через haproxy) и два экземпляра `PdoUserRepository`.
  `register` и `login` работают с primary, `search` читает реплики, `get` читает реплики через `PrimaryFallbackUserRepository`
  (свежесозданная анкета может ещё не дойти до реплики). Общего подключения и алиаса `UserRepository` по умолчанию нет:
  новый use case обязан выбрать явно. Подход одинаков в `native` и `symfony`.
- Таймауты соединения с БД в `PdoConnectionFactory` (`PDO::ATTR_TIMEOUT` и `tcp_user_timeout` в DSN) одинаковы в обоих приложениях.
  `connect_timeout` в DSN использовать нельзя: pdo_pgsql дописывает свой (30 с) позже, и он перекрывает. На DNS эти таймауты
  не действуют (за него отвечает `dns_opt` в compose).
- Без Doctrine и любого ORM. Только PDO с prepared statements. Запросы статические: даже список id передаётся одним
  параметром-массивом (`ANY(CAST(:ids AS uuid[]))`).
- Бизнес-логики в `symfony` нет: проверка владельца поста, границы и значения по умолчанию страницы ленты, вместимость ленты (1000),
  правила дружбы и текста поста живут в `core`. Здесь только SQL, Redis-команды, разбор HTTP и маппинг ошибок.
- Infrastructure реализует порты из `core` и не содержит бизнес-правил.
  Реализации по подходу эквивалентны `apps/native`.
- Сервисы `core` и свои реализации портов регистрируем в `services.yaml` явно,
  привязывая интерфейсы к реализациям.
- Атрибуты и классы Symfony допустимы только в Infrastructure и Presentation.
- Контроллеры тонкие: разобрать запрос, вызвать handler, вернуть ответ. Отступления от стандартных REST-практик из контракта
  (удаление через PUT, `/friend/set`, 204) помечены строкой-комментарием у `#[Route]`.
- Роутинг чтения и записи ленты (в `services.yaml`, как в `native`): создание, правка, удаление постов, друзья, `GET /post/get/{id}`
  и воркер работают с primary; холодная пересборка ленты и тела постов для страницы ленты читают реплику.
  `redis.connection` постоянное (php-fpm), открывается при первой команде.
- Маппинг ошибок в `ApiExceptionListener`: поле `friendId` домена в ответе называется `userId` (так зовётся параметр пути).
- Тесты: `tests/Unit` (разбор Authorization, параметры запроса, маппинг ошибок, 204) без инфраструктуры, `tests/Integration`
  на реальных PostgreSQL и Redis. Сквозная проверка контракта: Postman-коллекция через newman. Проверка контейнера DI:
  `bin/console lint:container` и `cache:warmup` в prod.
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