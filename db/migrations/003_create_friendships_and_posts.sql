-- Друзья и посты (ДЗ «Лента постов»).
-- Индексов сверх первичных ключей здесь нет: индексы для fan-out и сборки ленты добавляются
-- отдельными миграциями после замера (как 002 для поиска по имени).
--
-- Источник истины — эта схема; лента в Redis (ZSET) только кэш и перестраивается из этих таблиц.

-- Дружба односторонняя: строка (user_id, friend_id) значит «user_id добавил friend_id в друзья».
-- Лента пользователя строится по тем, кого он добавил сам.
-- - Первичный ключ (user_id, friend_id) нужен для корректности: он запрещает дубли и даёт идемпотентный
--   INSERT ... ON CONFLICT DO NOTHING для /friend/set. Заодно по префиксу user_id он отвечает на вопрос
--   «кого добавил пользователь» (сборка ленты).
-- - CHECK дублирует правило домена «нельзя добавить в друзья самого себя».
-- - Внешние ключи без ON DELETE CASCADE: контракт не удаляет пользователей.
-- - Запрос «кто добавил автора» (подписчики для fan-out) этим ключом не обслуживается; индекс по
--   (friend_id, user_id) добавляется отдельной миграцией после замера.
CREATE TABLE friendships (
    user_id    UUID        NOT NULL REFERENCES users (id),
    friend_id  UUID        NOT NULL REFERENCES users (id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, friend_id),
    CHECK (user_id <> friend_id)
);

-- Посты пользователей.
-- - id — UUID, который генерирует приложение (UUIDv7: упорядочен по времени, вставки в индекс локальны,
--   а при равном created_at порядок по id совпадает с хронологией). В PostgreSQL 17 своей uuidv7() нет.
-- - Порядок ленты везде «created_at DESC, id DESC»; в Redis score ленты — время создания в микросекундах,
--   а при равных score ZSET сортирует по member (id), так что порядок в БД и в кэше совпадает.
-- - text ограничен 2000 символов, как в docs/openapi.json; CHECK — страховка от пустого текста
--   поверх валидации в домене.
-- - Редактирование меняет только text и не трогает created_at: порядок ленты не меняется.
--   Удаление физическое (/post/delete), поэтому deleted_at нет.
-- - Запросы «последние посты автора» (сборка ленты, backfill после /friend/set) этим ключом не обслуживаются;
--   индекс по (author_id, created_at DESC, id DESC) добавляется отдельной миграцией после замера.
CREATE TABLE posts (
    id         UUID          PRIMARY KEY,
    author_id  UUID          NOT NULL REFERENCES users (id),
    text       VARCHAR(2000) NOT NULL CHECK (btrim(text) <> ''),
    created_at TIMESTAMPTZ   NOT NULL DEFAULT now()
);
