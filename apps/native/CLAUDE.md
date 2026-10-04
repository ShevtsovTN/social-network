# native: чистый PHP

## Роль
Presentation и Infrastructure поверх `core`. Бизнес-логики здесь нет.

## Состав
- `public/index.php`: единственная точка входа.
- `config/`: composition root, ручная сборка зависимостей (без autowiring).
- `src/Infrastructure/Persistence/`: `PdoUserRepository`, фабрика PDO-подключения.
- `src/Infrastructure/Security/`: хешер на argon2id, выпуск и проверка токенов.
- `src/Presentation/Http/`: `Application` (вход слоя: Request → JsonResponse) и подкаталоги по ответственности:
  `Request/` (запрос, JSON-тело, `MalformedRequest`), `Response/` (`JsonResponse`),
  `Routing/` (роутер, маршрут, `RouteNotFound`, `MethodNotAllowed`), `Controller/` (интерфейс и контроллеры),
  `Error/` (обработчик ошибок).

## Правила
- Никаких фреймворков и микрофреймворков. Мелкие библиотеки только по согласованию.
- Infrastructure реализует порты из `core` и не содержит бизнес-правил.
- Только prepared statements с именованными параметрами, без ORM.
- Параметры argon2id и алгоритм токенов идентичны Symfony-версии.
- Контейнер собирается вручную в одном месте.
- Сборка ленивая, как в Symfony: сервисы Infrastructure создаются через `$once(...)` при первом обращении,
  а контроллер с handler'ом создаётся фабрикой в `Route`, только когда маршрут подошёл. Неизвестный путь и 405 не открывают соединение с БД
  и не требуют переменных окружения.
- Контроллер: разобрать запрос, вызвать handler из `core`, сформировать ответ.
- Валидация формата запроса на границе, до вызова use case.
- Маппинг доменных исключений в HTTP-статусы в одном обработчике ошибок.
- Формат ответов и коды ошибок строго по `docs/openapi.json`.
- Интеграционные тесты наследуют contract-тесты из `core` и идут на реальном PostgreSQL.
- Не оптимизировать «в пользу» этого приложения: настройки PHP и ресурсы
  должны совпадать с Symfony-версией.
- Namespace приложения: `SocialNetwork\NativeApp\`.
