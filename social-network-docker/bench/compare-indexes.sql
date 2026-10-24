-- Сравнение вариантов индекса для GET /user/search на текущих данных (вывод: docs/homework-indexes/explain/index-variants.txt).
-- Каждый вариант создаётся в транзакции и откатывается, БД не меняется (кроме CREATE EXTENSION pg_trgm).
-- Набор запросов (search-queries.csv) приходит в stdin, вариант задаётся переменными psql:
--   docker compose cp social-network-docker/bench/compare-indexes.sql postgres:/tmp/
--   docker compose exec -T postgres sh -c 'psql -X -q -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
--       -v variant=last_first -v ddl="CREATE INDEX t_idx ON users (last_name varchar_pattern_ops, first_name varchar_pattern_ops)" \
--       -f /tmp/compare-indexes.sql' < social-network-docker/bench/search-queries.csv
-- Пустой ddl — замер без дополнительного индекса.
\set ON_ERROR_STOP on
\pset pager off
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Весь набор нагрузки: 1000 пар префиксов.
CREATE TEMP TABLE pairs (f text, l text);
\copy pairs FROM pstdin WITH (FORMAT csv)

-- Выполняет все 1000 запросов приложения и возвращает суммарное время, мс.
CREATE OR REPLACE FUNCTION pg_temp.run_all() RETURNS numeric LANGUAGE plpgsql AS $$
DECLARE
    started timestamptz := clock_timestamp();
    pair record;
    found bigint;
BEGIN
    FOR pair IN SELECT * FROM pairs LOOP
        EXECUTE 'SELECT count(*) FROM (SELECT id, first_name, last_name, birth_date, gender, interests, city, password_hash
                 FROM users WHERE first_name LIKE $1 AND last_name LIKE $2 ORDER BY id) s'
            INTO found USING pair.f || '%', pair.l || '%';
    END LOOP;
    RETURN round(extract(epoch FROM clock_timestamp() - started) * 1000, 0);
END $$;

\echo '=== variant' :variant
BEGIN;
SELECT :'ddl' <> '' AS has_index \gset
\if :has_index
    SELECT :'ddl' AS ddl \gset
    :ddl;
    ANALYZE users;
    SELECT string_agg(indexrelid::regclass || '=' || pg_size_pretty(pg_relation_size(indexrelid)), ', ') AS index_size
    FROM pg_index WHERE indrelid = 'users'::regclass AND NOT indisprimary;
\endif
SELECT pg_temp.run_all() AS warmup_ms;
SELECT pg_temp.run_all() AS all_1000_queries_ms;
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF)
SELECT id, first_name, last_name, birth_date, gender, interests, city, password_hash
FROM users WHERE first_name LIKE 'Кон%' AND last_name LIKE 'Оси%' ORDER BY id;
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF)
SELECT id, first_name, last_name, birth_date, gender, interests, city, password_hash
FROM users WHERE first_name LIKE 'Але%' AND last_name LIKE 'Ива%' ORDER BY id;
ROLLBACK;
