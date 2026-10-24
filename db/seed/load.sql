-- Заполнение users анкетами для ДЗ «Производительность индексов».
-- Запускается из seed.sh: CSV приходит в stdin psql (pstdin), формат строки
-- «Фамилия Имя,YYYY-MM-DD,Город». Итог: ровно :target_count анкет.
--
-- Все анкеты получают один заранее посчитанный хеш пароля «seed-password»
-- (argon2id с теми же параметрами, что у Argon2PasswordHasher: m=65536, t=4, p=1):
-- хешировать миллион паролей с такими параметрами заняло бы часы.

\set ON_ERROR_STOP on
\set target_count 1000000
\set password_hash '$argon2id$v=19$m=65536,t=4,p=1$S2ouRVZOT0hPdVdjLzVRLg$JaDWATMETje+ruVlzKipRn2+KuQKg8D9D4RlTsbltec'

BEGIN;

-- Сид рассчитан на пустую таблицу: иначе итог не будет ровно :target_count.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM users) THEN
        RAISE EXCEPTION 'Таблица users не пуста: выполните make reset-db и make up, затем повторите.';
    END IF;
END
$$;

CREATE TEMP TABLE people_import (
    full_name  TEXT NOT NULL,
    birth_date DATE NOT NULL,
    city       TEXT NOT NULL
) ON COMMIT DROP;

\copy people_import FROM pstdin WITH (FORMAT csv)

CREATE TEMP TABLE people ON COMMIT DROP AS
SELECT split_part(full_name, ' ', 2) AS first_name,
       split_part(full_name, ' ', 1) AS last_name,
       -- Пола в CSV нет. Женские фамилии в нём оканчиваются на «а» или «я»
       -- (Иванова, Черныха), мужские нет: проверено по всем 1006 фамилиям.
       CASE WHEN right(split_part(full_name, ' ', 1), 1) IN ('а', 'я') THEN 'female' ELSE 'male' END AS gender,
       birth_date,
       city
FROM people_import;

-- Недостающие до :target_count анкеты: имя, пол и город из одной случайной анкеты,
-- фамилия и дата рождения из другой анкеты того же пола. Имена и фамилии остаются реальными.
INSERT INTO people (first_name, last_name, gender, birth_date, city)
WITH shuffled AS (
    SELECT row_number() OVER (PARTITION BY gender ORDER BY random()) AS position, people.*
    FROM people
)
SELECT donor.first_name, other.last_name, donor.gender, other.birth_date, donor.city
FROM shuffled AS donor
JOIN shuffled AS other ON other.gender = donor.gender AND other.position = donor.position + 1
ORDER BY donor.position, donor.gender
LIMIT greatest(:target_count - (SELECT count(*) FROM people), 0);

INSERT INTO users (id, first_name, last_name, birth_date, gender, interests, city, password_hash)
SELECT gen_random_uuid(), first_name, last_name, birth_date, gender, '', city, :'password_hash'
FROM people;

COMMIT;

ANALYZE users;

SELECT count(*) AS users_total,
       count(*) FILTER (WHERE gender = 'female') AS female,
       count(*) FILTER (WHERE gender = 'male') AS male
FROM users;
