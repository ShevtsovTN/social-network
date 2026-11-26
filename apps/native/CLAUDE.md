# native: чистый PHP

## Роль
Presentation и Infrastructure поверх `core`. Бизнес-логики здесь нет.

## Состав
- `public/index.php`: единственная точка входа HTTP. `bin/feed-worker.php`: воркер ленты (цикл вокруг use case `ProcessNextFeedEvent`,
  при ошибке инфраструктуры выходит с кодом 1, перезапуск делает docker compose).
- `config/`: composition root, ручная сборка зависимостей (без autowiring).
- `src/Infrastructure/Persistence/`: `PdoUserRepository`, `PrimaryFallbackUserRepository` (чтение с реплики с возвратом к primary
  при промахе), фабрика PDO-подключения; для ДЗ «Лента постов»: `PdoPostRepository`, `PdoFriendshipRepository`, `PdoFeedSource`.
- `src/Infrastructure/Security/`: хешер на argon2id, выпуск и проверка токенов, `RandomPostIdGenerator` (UUIDv7).
- `src/Infrastructure/Redis/` (ДЗ «Лента постов»): `RedisConnection` (ленивое подключение, таймауты, превращает ошибки сервера
  в `RedisException`), `RedisKeyspace` (имена ключей с префиксом приложения), `LuaScript` (EVALSHA с запасным EVAL),
  `RedisFeedCache` (лента в ZSET, Lua-скрипты), `RedisPostCache` (тела постов в JSON с TTL). Сбои превращаются в `CacheUnavailable`.
- `src/Infrastructure/Queue/`: `FeedEventSerializer`, `RedisFeedEventPublisher` (best-effort, не бросает исключений),
  `RedisFeedEventConsumer` (consumer group, XAUTOCLAIM для зависших событий, XACK и XDEL; ошибки Redis пробрасываются).
- `src/Presentation/Http/`: `Application` (вход слоя: Request → JsonResponse) и подкаталоги по ответственности:
  `Request/` (запрос, JSON-тело, параметры строки запроса, `MalformedRequest`), `Response/` (`JsonResponse`, `UserPayload`),
  `Routing/` (роутер, маршрут, `RouteNotFound`, `MethodNotAllowed`), `Controller/` (интерфейс и контроллеры),
  `Error/` (обработчик ошибок), `Auth/` (`RequestAuthenticator`: токен из `Authorization: Bearer` в use case `Authenticate`).
  Ответы без результата — `JsonResponse::noContent()` (204) без заголовка `Content-Type` (иначе PHP добавил бы `text/html`, а Symfony ответ
  без заголовка; `make compare` это ловит). Контроллеры друзей, постов и ленты тонкие: аутентификация,
  разбор запроса, вызов use case, ответ; `offset` и `limit` ленты передаются в `core` строками как пришли.

## Правила
- Никаких фреймворков и микрофреймворков. Мелкие библиотеки только по согласованию.
- Infrastructure реализует порты из `core` и не содержит бизнес-правил.
- Маршрутизация чтения и записи (ДЗ «Репликация»), решение принимается в composition root (`config/container.php`), не в репозитории:
  два PDO (`DB_HOST` primary, `DB_READ_HOST` реплики через haproxy) и два экземпляра `PdoUserRepository`.
  `register` и `login` работают с primary, `search` читает реплики, `get` читает реплики через `PrimaryFallbackUserRepository`
  (свежесозданная анкета может ещё не дойти до реплики). Общего подключения и алиаса `UserRepository` по умолчанию нет:
  новый use case обязан выбрать явно. Подход одинаков в `native` и `symfony`.
- Таймауты соединения с БД в `PdoConnectionFactory` (`PDO::ATTR_TIMEOUT` и `tcp_user_timeout` в DSN) одинаковы в обоих приложениях.
  `connect_timeout` в DSN использовать нельзя: pdo_pgsql дописывает свой (30 с) позже, и он перекрывает. На DNS эти таймауты
  не действуют (за него отвечает `dns_opt` в compose).
- Redis: подключение ленивое (недоступный Redis обнаруживается внутри кэша, а не при сборке контроллера, иначе чтение ленты
  не смогло бы уйти в БД). Лента: score = микросекунды создания, служебный элемент `-` со score 0 держит пустую ленту живой,
  вместимость берётся из `FeedLimits::CAPACITY`. Таймаут чтения `RedisConnection` у воркера должен быть больше блокирующего ожидания
  `XREADGROUP`, иначе phpredis обрывает соединение. phpredis возвращает ошибки сервера как `false`, а не исключение:
  команды выполняются через `RedisConnection::execute()`.
- Только prepared statements с именованными параметрами, без ORM. Запросы статические: даже список id передаётся одним
  параметром-массивом (`ANY(CAST(:ids AS uuid[]))`), а не собирается из плейсхолдеров.
- Бизнес-логики в `native` нет: проверка владельца поста, границы и значения по умолчанию страницы ленты, вместимость ленты (1000),
  правила дружбы и текста поста живут в `core`. Здесь только SQL, Redis-команды, разбор HTTP и маппинг ошибок.
  Константы берутся из `core` (например, `FeedLimits::CAPACITY`), а не дублируются.
- Параметры argon2id и алгоритм токенов идентичны Symfony-версии.
- Контейнер собирается вручную в одном месте.
- Сборка ленивая, как в Symfony: сервисы Infrastructure создаются через `$once(...)` при первом обращении,
  а контроллер с handler'ом создаётся фабрикой в `Route`, только когда маршрут подошёл. Неизвестный путь и 405 не открывают соединение с БД
  и не требуют переменных окружения.
- Контроллер: разобрать запрос, вызвать handler из `core`, сформировать ответ.
- Валидация формата запроса на границе, до вызова use case.
- Маппинг доменных исключений в HTTP-статусы в одном обработчике ошибок. Поле `friendId` домена в ответе называется `userId`
  (так зовётся параметр пути), другие поля передаются как есть.
- Тесты: `tests/Unit` (разбор Authorization, параметры запроса, маппинг ошибок, ответы) без инфраструктуры,
  `tests/Integration` на реальных PostgreSQL и Redis. Сквозная проверка контракта: Postman-коллекция через newman.
- Формат ответов и коды ошибок строго по `docs/openapi.json`.
- Интеграционные тесты наследуют contract-тесты из `core` и идут на реальном PostgreSQL.
- Не оптимизировать «в пользу» этого приложения: настройки PHP и ресурсы
  должны совпадать с Symfony-версией.
- Namespace приложения: `SocialNetwork\NativeApp\`.
