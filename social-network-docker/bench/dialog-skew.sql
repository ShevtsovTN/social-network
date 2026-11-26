-- Распределение сообщений по воркерам при двух ключах шардирования (make dialogs-skew, см. dialog-skew.sh).
\set ON_ERROR_STOP on

SELECT user_id AS gaga FROM seed_dialog_roles WHERE role = 'gaga' LIMIT 1 \gset

DROP TABLE IF EXISTS skew_by_sender;
SET client_min_messages = warning;
CREATE TABLE skew_by_sender (LIKE dialog_messages);
SELECT create_distributed_table('skew_by_sender', 'sender_id', shard_count => 64, colocate_with => 'none') \g /dev/null
INSERT INTO skew_by_sender SELECT * FROM dialog_messages;

-- Размер каждого шарда в сообщениях: всех и написанных «Леди Гагой».
CREATE TEMP TABLE shard_load AS
SELECT 'dialog_id' AS shard_key, s.nodename, s.shardid, c.result::bigint AS messages
FROM citus_shards AS s
JOIN run_command_on_shards('dialog_messages', 'SELECT count(*) FROM %s') AS c USING (shardid)
UNION ALL
SELECT 'sender_id', s.nodename, s.shardid, c.result::bigint
FROM citus_shards AS s
JOIN run_command_on_shards('skew_by_sender', 'SELECT count(*) FROM %s') AS c USING (shardid);

CREATE TEMP TABLE gaga_load AS
SELECT 'dialog_id' AS shard_key, s.nodename, s.shardid, c.result::bigint AS messages
FROM citus_shards AS s
JOIN run_command_on_shards('dialog_messages', format('SELECT count(*) FROM %%s WHERE sender_id = %L', :'gaga')) AS c USING (shardid)
UNION ALL
SELECT 'sender_id', s.nodename, s.shardid, c.result::bigint
FROM citus_shards AS s
JOIN run_command_on_shards('skew_by_sender', format('SELECT count(*) FROM %%s WHERE sender_id = %L', :'gaga')) AS c USING (shardid);

\echo 'Все сообщения по воркерам'
SELECT shard_key, nodename, sum(messages) AS messages,
       round(100 * sum(messages) / sum(sum(messages)) OVER (PARTITION BY shard_key), 1) AS share_percent
FROM shard_load
GROUP BY shard_key, nodename
ORDER BY shard_key, nodename;

\echo 'Сообщения «Леди Гаги» по воркерам'
SELECT shard_key, nodename, sum(messages) AS messages,
       round(100 * sum(messages) / sum(sum(messages)) OVER (PARTITION BY shard_key), 1) AS share_percent,
       count(*) FILTER (WHERE messages > 0) AS shards_with_her_messages
FROM gaga_load
GROUP BY shard_key, nodename
ORDER BY shard_key, nodename;

\echo 'Перекос шардов: самый большой шард относительно среднего'
SELECT shard_key, count(*) AS shards, round(avg(messages)) AS average, max(messages) AS largest,
       round(max(messages) / avg(messages), 1) AS largest_to_average
FROM shard_load
GROUP BY shard_key
ORDER BY shard_key;

DROP TABLE skew_by_sender;
