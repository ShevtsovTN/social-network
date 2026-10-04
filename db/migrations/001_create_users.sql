-- Пользователи социальной сети.
-- Индексов сверх первичного ключа нет, как требует задание.
-- Пароль хранится только в виде хеша (argon2id), в открытом виде не сохраняется.

CREATE TABLE users (
    id            UUID         PRIMARY KEY,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    birth_date    DATE         NOT NULL,
    gender        VARCHAR(16)  NOT NULL,
    interests     TEXT         NOT NULL DEFAULT '',
    city          VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
