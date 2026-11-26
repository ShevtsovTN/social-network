-- Индекс для последних постов автора (сборка ленты при промахе кэша и backfill после /friend/set):
--   ... FROM posts WHERE author_id = :author ORDER BY created_at DESC, id DESC LIMIT 1000
--   (сборка ленты: тот же порядок поверх JOIN по friendships.friend_id = posts.author_id)
--
-- Почему такой:
-- - Первичный ключ posts (id) по author_id не ищет: запрос читал весь миллион постов (около 0,7 с).
-- - (author_id, created_at DESC, id DESC) совпадает с порядком ленты («created_at DESC, id DESC»): последние посты
--   автора берутся с начала диапазона индекса без сортировки, а Index Only Scan не ходит в таблицу.
--   Для ленты друзей планировщик берёт у каждого друга начало диапазона и сливает их по порядку.
-- Замер до и после: docs/homework-feed/README.md.

CREATE INDEX posts_author_id_created_at_id_idx
    ON posts (author_id, created_at DESC, id DESC);
