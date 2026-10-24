-- Индекс для поиска анкет GET /user/search:
--   WHERE first_name LIKE 'префикс%' AND last_name LIKE 'префикс%' ORDER BY id
--
-- Почему такой:
-- - B-tree: префиксный LIKE превращается в диапазон (last_name >= 'Оси' AND last_name < 'Осй'), его B-tree и обслуживает.
-- - varchar_pattern_ops: collation базы en_US.utf8, а с ним обычный B-tree для LIKE не используется
--   (порядок строк по правилам локали не совпадает с побайтовым). Классы *_pattern_ops сравнивают посимвольно.
-- - Составной на обе колонки: условие по второй колонке проверяется прямо в индексе (Index Cond),
--   в таблицу идём только за подходящими строками.
-- - Фамилия первой: она селективнее имени (1006 различных фамилий против 138 имён), диапазон по ней короче.
-- Сравнение с другими вариантами (обратный порядок, два отдельных индекса, GIN pg_trgm): docs/homework-indexes/README.md.

CREATE INDEX users_last_name_first_name_prefix_idx
    ON users (last_name varchar_pattern_ops, first_name varchar_pattern_ops);
