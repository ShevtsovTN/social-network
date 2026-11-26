-- Состояние кластера диалогов (make dialogs-status): узлы и распределение шардов по воркерам.
\echo Узлы кластера
SELECT nodename, isactive, shouldhaveshards, metadatasynced FROM pg_dist_node ORDER BY nodeid;

\echo Шарды по узлам
SELECT nodename, count(*) AS shards, pg_size_pretty(sum(shard_size)) AS size
FROM citus_shards
GROUP BY nodename
ORDER BY nodename;
