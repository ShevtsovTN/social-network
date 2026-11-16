-- EXPLAIN (ANALYZE, BUFFERS) ключевых запросов ленты на текущих данных
-- (вывод: docs/homework-feed/explain/<фаза>.txt, цель make explain-feed PHASE=имя).
-- Запросы те же, что в PdoFeedSource и PdoFriendshipRepository. Значения берутся из данных:
-- «обычный» читатель и автор имеют по 20 связей, «знаменитость» — автор с максимумом подписчиков.
-- Каждый запрос выполняется дважды: первый прогон (прогрев кэша страниц) скрыт, показан второй.
\set ON_ERROR_STOP on
\pset pager off

SELECT user_id AS reader FROM friendships GROUP BY user_id HAVING count(*) = 20 ORDER BY user_id LIMIT 1 \gset
SELECT friend_id AS small_author FROM friendships GROUP BY friend_id HAVING count(*) = 20 ORDER BY friend_id LIMIT 1 \gset
SELECT friend_id AS celebrity FROM friendships GROUP BY friend_id ORDER BY count(*) DESC, friend_id LIMIT 1 \gset
SELECT count(*) AS celebrity_followers FROM friendships WHERE friend_id = :'celebrity' \gset
SELECT count(*) AS small_author_posts FROM posts WHERE author_id = :'small_author' \gset
SELECT count(*) AS celebrity_posts FROM posts WHERE author_id = :'celebrity' \gset

\echo '== Данные =='
\echo читатель :reader, 20 друзей
\echo обычный автор :small_author, 20 подписчиков, постов :small_author_posts
\echo «знаменитость» :celebrity, подписчиков :celebrity_followers, постов :celebrity_posts
SELECT current_setting('server_version') AS server_version, current_setting('shared_buffers') AS shared_buffers,
       current_setting('work_mem') AS work_mem;

-- Первая страница подписчиков автора (FOLLOWERS_BATCH_SIZE = 500) для fan-out поста.
\echo
\echo '== 1. followerIdsOf: подписчики обычного автора (fan-out нового поста), LIMIT 500 =='
\o /dev/null
EXPLAIN (ANALYZE, BUFFERS) SELECT user_id FROM friendships WHERE friend_id = :'small_author' ORDER BY user_id LIMIT 500;
\o
EXPLAIN (ANALYZE, BUFFERS) SELECT user_id FROM friendships WHERE friend_id = :'small_author' ORDER BY user_id LIMIT 500;

\echo
\echo '== 2. followerIdsOf: первая страница подписчиков «знаменитости», LIMIT 500 =='
\o /dev/null
EXPLAIN (ANALYZE, BUFFERS) SELECT user_id FROM friendships WHERE friend_id = :'celebrity' ORDER BY user_id LIMIT 500;
\o
EXPLAIN (ANALYZE, BUFFERS) SELECT user_id FROM friendships WHERE friend_id = :'celebrity' ORDER BY user_id LIMIT 500;

\echo
\echo '== 3. latestFromFriends: сборка ленты читателя при промахе кэша, LIMIT 1000 =='
\o /dev/null
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.created_at FROM friendships f JOIN posts p ON p.author_id = f.friend_id
 WHERE f.user_id = :'reader' ORDER BY p.created_at DESC, p.id DESC LIMIT 1000;
\o
EXPLAIN (ANALYZE, BUFFERS) SELECT p.id, p.created_at FROM friendships f JOIN posts p ON p.author_id = f.friend_id
 WHERE f.user_id = :'reader' ORDER BY p.created_at DESC, p.id DESC LIMIT 1000;

\echo
\echo '== 4. latestByAuthor: backfill после /friend/set (обычный автор), LIMIT 1000 =='
\o /dev/null
EXPLAIN (ANALYZE, BUFFERS) SELECT id, created_at FROM posts WHERE author_id = :'small_author' ORDER BY created_at DESC, id DESC LIMIT 1000;
\o
EXPLAIN (ANALYZE, BUFFERS) SELECT id, created_at FROM posts WHERE author_id = :'small_author' ORDER BY created_at DESC, id DESC LIMIT 1000;

\echo
\echo '== 5. latestByAuthor: backfill «знаменитости», LIMIT 1000 =='
\o /dev/null
EXPLAIN (ANALYZE, BUFFERS) SELECT id, created_at FROM posts WHERE author_id = :'celebrity' ORDER BY created_at DESC, id DESC LIMIT 1000;
\o
EXPLAIN (ANALYZE, BUFFERS) SELECT id, created_at FROM posts WHERE author_id = :'celebrity' ORDER BY created_at DESC, id DESC LIMIT 1000;
