# core: бизнес-логика

## Состав
- `Domain`: сущности, value objects, доменные исключения.
  Зависит только от стандартной библиотеки PHP.
- `Application`: use cases (command/query + handler) и порты
  (`UserRepository`, `PasswordHasher`, `TokenIssuer`, `TokenVerifier`, `UserIdGenerator`, `Clock`;
  для ДЗ «Лента постов»: `PostRepository`, `FriendshipRepository`, `FeedSource`, `FeedCache`, `PostCache`,
  `FeedEventPublisher`, `FeedEventConsumer`, `PostIdGenerator`, исключение `CacheUnavailable`).
  Зависит только от Domain.
- Use cases: `RegisterUser` (возвращает `UserId`), `Login` (возвращает токен, при любой
  неудаче `InvalidCredentials`), `GetUser` (возвращает `User`, иначе `UserNotFound`),
  `SearchUsers` (список `User` по префиксам имени и фамилии, отсортирован по id; префикс — `NamePrefix`).
- Use cases ДЗ «Лента постов» (команды принимают уже проверенного `UserId` актёра): `Authenticate` (токен → `UserId`,
  иначе `Unauthenticated`), `AddFriend` и `RemoveFriend` (идемпотентны), `CreatePost` (возвращает `PostId`),
  `UpdatePost` и `DeletePost` (чужой и несуществующий пост одинаково `PostNotFound`), `GetPost`, `GetFeed`
  (`list<Post>`; при `CacheUnavailable` читает прямо из БД), `ApplyFeedEvent` (обновляет кэши по `FeedEvent`,
  идемпотентно), `ProcessNextFeedEvent` (один шаг воркера: получить, применить, подтвердить).
- События (`Application\Feed\Event`): `PostPublished`, `PostEdited`, `PostDeleted`, `FriendAdded`, `FriendRemoved`.
  Use cases публикуют их после записи в БД (`FeedEventPublisher` best-effort, не бросает исключений); воркер
  применяет асинхронно. Fan-out пишет только в уже существующие ленты (`FeedCache::mergeIfPresent`), остальные
  собираются лениво при чтении (`FeedSource` читает БД; какой PDO ему дать, решает composition root:
  холодная пересборка с реплики, backfill воркера с primary).
- Ошибки данных: `Domain\User\InvalidUserData`, `Domain\Post\InvalidPostData`, `Domain\Friend\InvalidFriendship`,
  `Domain\Feed\InvalidFeedPage` с полем `field` в терминах домена (`firstName`, `text`, `friendId`, `limit`, ...).
  Сопоставление с полями запроса и HTTP-статусами делают приложения.
- Реализаций портов в `core` нет. Их пишут приложения.

## Запреты
- Никакой инфраструктуры: PDO, SQL, файловая система, сеть, `password_hash`, JWT-библиотеки.
- Никаких HTTP-понятий: Symfony, PSR-7/15, статус-коды, заголовки.
- Никаких статических вызовов, синглтонов, глобального состояния.
- Никаких `$_SERVER`, `$_ENV`, `getenv()`.
- Исключения домена не знают про HTTP-коды.

## Правила
- Один use case = один интерфейс (`RegisterUser`, `Login`, `GetUser`, `SearchUsers`, `GetFeed`, ...) и один handler, который его реализует,
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
  Репозитории ленты (`PostRepository`, `FriendshipRepository`, `FeedSource`) тестируются над общим хранилищем
  `FeedStorage` (друзья ссылаются на пользователей, посты на авторов); очередь событий — парой `FeedEventChannel`
  (издатель и потребитель над одним потоком, неподтверждённое событие доставляется повторно сразу);
  `FeedFixture` даёт id, время и посты с возрастающими номерами. Кэши (`FeedCache`, `PostCache`) отличают
  промах (`null`) от пустой ленты (`[]`).
- In-memory реализации всех портов лежат в `tests/Unit/Fake` и сами проходят contract-тесты (`InMemory…Test`).
- Namespace `SocialNetwork\CoreContract\` (папка `tests/Contract`) входит в `autoload`,
  а не в `autoload-dev`, чтобы приложения могли подключать contract-тесты.
  PHPUnit при этом остаётся dev-зависимостью приложений.