# core: бизнес-логика

## Состав
- `Domain`: сущности, value objects, доменные исключения.
  Зависит только от стандартной библиотеки PHP.
- `Application`: use cases (command/query + handler) и порты
  (`UserRepository`, `PasswordHasher`, `TokenIssuer`, `TokenVerifier`, `UserIdGenerator`, `Clock`).
  Зависит только от Domain.
- Use cases: `RegisterUser` (возвращает `UserId`), `Login` (возвращает токен, при любой
  неудаче `InvalidCredentials`), `GetUser` (возвращает `User`, иначе `UserNotFound`).
- Ошибки данных: `Domain\User\InvalidUserData` с полем `field` в терминах домена
  (`firstName`, `birthDate`, ...). Сопоставление с полями запроса и HTTP-статусами делают приложения.
- Реализаций портов в `core` нет. Их пишут приложения.

## Запреты
- Никакой инфраструктуры: PDO, SQL, файловая система, сеть, `password_hash`, JWT-библиотеки.
- Никаких HTTP-понятий: Symfony, PSR-7/15, статус-коды, заголовки.
- Никаких статических вызовов, синглтонов, глобального состояния.
- Никаких `$_SERVER`, `$_ENV`, `getenv()`.
- Исключения домена не знают про HTTP-коды.

## Правила
- Один use case = один интерфейс (`RegisterUser`, `Login`, `GetUser`) и один handler, который его реализует,
  с одним публичным методом. Контракт ошибок (`@throws`) описывается на интерфейсе.
  Presentation приложений зависит от интерфейса, а не от handler'а.
- Входные данные use case приходят готовыми DTO, а не массивами.
- Порты описывают потребности бизнеса, а не технологию
  (например, `PasswordHasher::hash/verify`, а не `Argon2Hasher`).
- Инварианты (формат даты рождения, допустимые значения пола, непустое имя)
  проверяются в value objects.
- Каждый use case покрыт unit-тестом на in-memory фейках портов, без БД и фреймворка.
- `tests/Contract` содержит абстрактные тест-кейсы портов. Приложения наследуют их
  и подставляют свою реализацию. Менять контракт порта можно только вместе с этими тестами.
- Namespace `SocialNetwork\CoreContract\` (папка `tests/Contract`) входит в `autoload`,
  а не в `autoload-dev`, чтобы приложения могли подключать contract-тесты.
  PHPUnit при этом остаётся dev-зависимостью приложений.