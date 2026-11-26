-- Заполнение friendships и posts для ДЗ «Лента постов». Запускается из seed-feed.sh: тексты постов приходят в stdin psql
-- (pstdin), по одному посту на строку (posts.txt из OTUS).
--
-- Модель данных (параметры ниже):
-- - «Активные» пользователи (:active_users штук, случайная воспроизводимая выборка из users): только они дружат и пишут посты.
--   Остальные анкеты нужны для поиска и в ленте не участвуют.
-- - Друзья: у каждого активного от 5 до 50 исходящих связей (в среднем 20), цели выбираются с сильным перекосом (random()^3):
--   у нескольких «знаменитостей» десятки тысяч подписчиков, у большинства единицы. Связь односторонняя, как в контракте.
-- - Посты: :total_posts штук у активных авторов с перекосом (random()^1.5), в среднем 10 на автора. Автор поста не связан
--   с популярностью: индекс автора пропускается через умножение по модулю, поэтому «знаменитость» не обязательно самый плодовитый автор.
-- - Время создания равномерно за последние 90 дней, с микросекундами. id постов — gen_random_uuid() (v4): в PostgreSQL 17 нет
--   uuidv7(), приложения создают v7, но на чтение это не влияет.
--
-- Индексов сверх первичных ключей здесь нет: индексы для ленты добавляются миграциями после замера.

\set ON_ERROR_STOP on
\set active_users 100000
\set total_posts 1000000
\timing on

BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM friendships) OR EXISTS (SELECT 1 FROM posts) THEN
        RAISE EXCEPTION 'Таблицы friendships или posts не пусты: выполните make reset-feed, затем повторите.';
    END IF;
END
$$;

\echo '--- тексты постов'
CREATE TEMP TABLE post_texts_import (line TEXT NOT NULL) ON COMMIT DROP;

-- Строки читаются как есть: разделитель и кавычка — управляющие символы, которых в тексте нет.
\copy post_texts_import FROM pstdin WITH (FORMAT csv, DELIMITER E'\x02', QUOTE E'\x01')

CREATE TEMP TABLE post_texts ON COMMIT DROP AS
SELECT row_number() OVER ()::int AS position, btrim(line) AS text
FROM post_texts_import
WHERE btrim(line) <> '';

SELECT count(*) AS text_count FROM post_texts \gset

DO $$
BEGIN
    IF (SELECT count(*) FROM post_texts) = 0 THEN
        RAISE EXCEPTION 'В stdin нет текстов постов (ожидается posts.txt, по посту на строку).';
    END IF;
END
$$;

\echo '--- активные пользователи'
SELECT setseed(0.42);

CREATE TEMP TABLE active ON COMMIT DROP AS
SELECT id, row_number() OVER ()::int AS n
FROM (SELECT id FROM users ORDER BY random() LIMIT :active_users) AS picked;

CREATE UNIQUE INDEX active_n_idx ON active (n);
ANALYZE active;

SELECT count(*) AS active_count FROM active \gset

DO $$
BEGIN
    IF (SELECT count(*) FROM active) < 1000 THEN
        RAISE EXCEPTION 'В users слишком мало анкет (нужен make seed).';
    END IF;
END
$$;

\echo '--- друзья'
-- degree считается один раз на пользователя (OFFSET 0 не даёт планировщику подставить подзапрос и пересчитать random()).
INSERT INTO friendships (user_id, friend_id)
SELECT DISTINCT e.user_id, target.id
FROM (
    SELECT a.id AS user_id, 1 + floor(:active_count * random() ^ 3)::int AS target_n
    FROM (SELECT id, 5 + floor(45 * random() ^ 2)::int AS degree FROM active OFFSET 0) AS a
    CROSS JOIN LATERAL generate_series(1, a.degree)
) AS e
JOIN active AS target ON target.n = e.target_n
WHERE target.id <> e.user_id
ORDER BY e.user_id, target.id;

\echo '--- посты'
-- Автор, время и номер считаются в подзапросе на каждую строку (OFFSET 0 не даёт планировщику подставить подзапрос
-- и вычислить random() один раз). Перекос по авторам, затем перестановка (48271 взаимно просто с :active_count,
-- не кратным 3, 7, 11, 31): плодовитые авторы не совпадают со «знаменитостями» среди друзей.
INSERT INTO posts (id, author_id, text, created_at)
SELECT gen_random_uuid(),
       author.id,
       post_texts.text,
       now() - make_interval(secs => g.age_seconds)
FROM (
    SELECT s.i,
           1 + ((floor(:active_count * random() ^ 1.5)::bigint * 48271) % :active_count)::int AS author_n,
           random() * 90 * 86400 AS age_seconds
    FROM generate_series(1, :total_posts) AS s(i)
    OFFSET 0
) AS g
JOIN active AS author ON author.n = g.author_n
JOIN post_texts ON post_texts.position = 1 + ((g.i::bigint * 7919) % :text_count)::int;

COMMIT;

\echo '--- ANALYZE'
ANALYZE friendships;
ANALYZE posts;

\echo '--- итоги'
SELECT (SELECT count(*) FROM friendships) AS friendships,
       (SELECT count(*) FROM posts) AS posts,
       (SELECT count(DISTINCT user_id) FROM friendships) AS users_with_friends,
       (SELECT count(DISTINCT author_id) FROM posts) AS authors;

SELECT min(c) AS min_friends, round(avg(c), 1) AS avg_friends, percentile_cont(0.5) WITHIN GROUP (ORDER BY c) AS median_friends, max(c) AS max_friends
FROM (SELECT count(*) AS c FROM friendships GROUP BY user_id) AS per_user;

SELECT friend_id AS most_followed_author, count(*) AS followers
FROM friendships GROUP BY friend_id ORDER BY followers DESC LIMIT 5;

SELECT min(c) AS min_posts, round(avg(c), 1) AS avg_posts, percentile_cont(0.5) WITHIN GROUP (ORDER BY c) AS median_posts, max(c) AS max_posts
FROM (SELECT count(*) AS c FROM posts GROUP BY author_id) AS per_author;

SELECT min(created_at) AS oldest_post, max(created_at) AS newest_post, round(avg(length(text))) AS avg_text_length FROM posts;

-- Сколько постов друзей видит читатель (размер ленты до обрезки до 1000).
SELECT percentile_cont(ARRAY[0.1, 0.5, 0.9, 0.99]) WITHIN GROUP (ORDER BY n) AS feed_size_p10_p50_p90_p99, max(n) AS feed_size_max
FROM (
    SELECT f.user_id, count(*) AS n
    FROM (SELECT user_id FROM friendships GROUP BY user_id ORDER BY random() LIMIT 2000) AS sample
    JOIN friendships f ON f.user_id = sample.user_id
    JOIN posts p ON p.author_id = f.friend_id
    GROUP BY f.user_id
) AS sizes;
