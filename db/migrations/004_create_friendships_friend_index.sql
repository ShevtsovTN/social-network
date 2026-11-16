-- Индекс для подписчиков автора (fan-out нового поста и backfill):
--   SELECT user_id FROM friendships WHERE friend_id = :author ORDER BY user_id LIMIT 500
--   (постранично: AND user_id > :after)
--
-- Почему такой:
-- - Первичный ключ (user_id, friend_id) начинается с user_id и по friend_id не ищет: у обычного автора запрос
--   читал всю таблицу (Parallel Seq Scan, около 190 мс на 1,95 млн связей), а воркер разбирал около 9 событий в секунду.
-- - (friend_id, user_id): равенство по первой колонке, вторая задаёт порядок страниц и keyset-условие user_id > :after,
--   поэтому сортировки нет, а Index Only Scan не ходит в таблицу.
-- Замер до и после: docs/homework-feed/README.md.

CREATE INDEX friendships_friend_id_user_id_idx
    ON friendships (friend_id, user_id);
