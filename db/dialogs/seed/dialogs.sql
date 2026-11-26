-- Тестовые диалоги для ДЗ «Шардинг диалогов». Выполняется на координаторе Citus из seed-dialogs.sh:
-- id участников (выборка из users основной БД) приходят в stdin psql (pstdin), по одному на строку.
--
-- Модель данных с перекосом (всего 2 000 000 сообщений за последние 90 дней):
-- - «Леди Гага» (участник 1): 400 000 сообщений в диалогах с 20 000 собеседников, 90% написала она сама (20% всех сообщений).
-- - «Горячий диалог» (участники 2 и 3): 200 000 сообщений в одной переписке (10% всех сообщений).
-- - Фон: 1 400 000 сообщений в 70 000 диалогах остальных участников, с перекосом по диалогам (random()^2).
--
-- Таблица seed_dialog_roles (локальная, на координаторе) запоминает, кто есть кто: по ней строятся нагрузка и замеры.
-- Сид добавляет сообщения к уже существующим и выполняется один раз: повторный запуск останавливается на этой таблице.

\set ON_ERROR_STOP on
\set background_dialogs 70000
\set background_messages 1400000
\set gaga_partners 20000
\set gaga_messages 400000
\set hot_messages 200000
\set role_sample 2000

DO $$
BEGIN
    IF to_regclass('public.seed_dialog_roles') IS NOT NULL THEN
        RAISE EXCEPTION 'Диалоги уже засеяны (есть таблица seed_dialog_roles). Заново: make dialogs-rebuild (удаляет все сообщения).';
    END IF;
END
$$;

BEGIN;

-- Тот же способ вычисления dialog_id, что в core (Domain\Dialog\DialogId): первые 16 байт SHA-256 строки
-- «меньший id:больший id» с битами версии 8 и варианта UUID.
-- Citus предупреждает, что временная функция создаётся только на координаторе: так и задумано.
SET LOCAL client_min_messages = error;
CREATE FUNCTION pg_temp.dialog_id(a uuid, b uuid) RETURNS uuid LANGUAGE sql IMMUTABLE AS $$
    SELECT encode(set_byte(set_byte(digest, 6, (get_byte(digest, 6) & 15) | 128), 8, (get_byte(digest, 8) & 63) | 128), 'hex')::uuid
    FROM (SELECT substring(sha256(convert_to(
              least(a::text COLLATE "C", b::text COLLATE "C") || ':' || greatest(a::text COLLATE "C", b::text COLLATE "C"), 'UTF8'
          )) FROM 1 FOR 16) AS digest) AS hashed
$$;

SET LOCAL client_min_messages = warning;

-- Эталон из packages/core/tests/Unit/Domain/DialogIdTest.php: расхождение сделало бы засеянные диалоги ненаходимыми.
DO $$
BEGIN
    IF pg_temp.dialog_id('c0000000-0000-4000-8000-000000000002', 'c0000000-0000-4000-8000-000000000001')
        <> '5bf65637-1159-8737-9ae3-acb27957e2e7' THEN
        RAISE EXCEPTION 'dialog_id в сиде считается не так, как в core.';
    END IF;
END
$$;

SELECT setseed(0.42) \g /dev/null

\echo '--- участники'
CREATE TEMP TABLE users_import (id uuid NOT NULL) ON COMMIT DROP;
\copy users_import FROM pstdin

CREATE TEMP TABLE participants ON COMMIT DROP AS
SELECT row_number() OVER (ORDER BY md5(id::text))::int AS n, id FROM users_import;
CREATE UNIQUE INDEX participants_n_idx ON participants (n);
ANALYZE participants;

SELECT count(*) AS participant_count FROM participants \gset
SELECT :participant_count > :gaga_partners + 3 AS enough \gset
\if :enough
\else
    \echo 'Слишком мало участников: заполните users (make seed).'
    SELECT 1 / 0;
\endif

\echo '--- план переписки'
-- Участники фоновых диалогов — с номера 4: 1, 2 и 3 заняты «Леди Гагой» и горячим диалогом. b всегда отличается от a.
CREATE TEMP TABLE background ON COMMIT DROP AS
SELECT dialog, a, 4 + (a - 4 + 1 + floor(random() * (:participant_count - 4))::int) % (:participant_count - 3) AS b
FROM (
    SELECT dialog, 4 + floor(random() * (:participant_count - 3))::int AS a
    FROM generate_series(1, :background_dialogs) AS dialog
    OFFSET 0
) AS firsts;

CREATE TEMP TABLE planned (sender int NOT NULL, recipient int NOT NULL) ON COMMIT DROP;

INSERT INTO planned
SELECT CASE WHEN m.forward THEN b.a ELSE b.b END, CASE WHEN m.forward THEN b.b ELSE b.a END
FROM (
    SELECT 1 + floor(power(random(), 2) * :background_dialogs)::int AS dialog, random() < 0.5 AS forward
    FROM generate_series(1, :background_messages)
    OFFSET 0
) AS m
JOIN background AS b USING (dialog);

INSERT INTO planned
SELECT CASE WHEN m.outgoing THEN 1 ELSE m.partner END, CASE WHEN m.outgoing THEN m.partner ELSE 1 END
FROM (
    SELECT 4 + floor(random() * :gaga_partners)::int AS partner, random() < 0.9 AS outgoing
    FROM generate_series(1, :gaga_messages)
    OFFSET 0
) AS m;

INSERT INTO planned
SELECT CASE WHEN m.forward THEN 2 ELSE 3 END, CASE WHEN m.forward THEN 3 ELSE 2 END
FROM (SELECT random() < 0.5 AS forward FROM generate_series(1, :hot_messages) OFFSET 0) AS m;

\echo '--- сообщения (до минуты)'
SELECT (extract(epoch FROM now()) * 1000)::bigint AS now_ms \gset

-- id — UUIDv7: 48 бит времени в миллисекундах, поэтому порядок id совпадает со временем отправки.
INSERT INTO dialog_messages (dialog_id, id, sender_id, recipient_id, text, created_at)
SELECT pg_temp.dialog_id(s.id, r.id),
       (lpad(to_hex(m.ms), 12, '0') || '7' || substr(m.noise, 1, 3) || '8' || substr(m.noise, 4, 15))::uuid,
       s.id,
       r.id,
       'Сообщение ' || substr(m.noise, 1, 8) || repeat(' и ещё немного текста', m.repeats),
       to_timestamp(m.ms / 1000.0)
FROM (
    SELECT sender,
           recipient,
           :now_ms - floor(random() * 7776000000)::bigint AS ms,
           md5(random()::text) AS noise,
           floor(random() * 8)::int AS repeats
    FROM planned
    OFFSET 0
) AS m
JOIN participants AS s ON s.n = m.sender
JOIN participants AS r ON r.n = m.recipient;

\echo '--- роли участников'
CREATE TABLE seed_dialog_roles (
    role         text NOT NULL,
    user_id      uuid NOT NULL,
    companion_id uuid NOT NULL,
    dialog_id    uuid NOT NULL
);

INSERT INTO seed_dialog_roles
SELECT 'hot', s.id, r.id, pg_temp.dialog_id(s.id, r.id)
FROM participants AS s JOIN participants AS r ON (s.n, r.n) IN ((2, 3), (3, 2));

INSERT INTO seed_dialog_roles
SELECT 'gaga', g.id, p.id, pg_temp.dialog_id(g.id, p.id)
FROM participants AS g JOIN participants AS p ON g.n = 1 AND p.n BETWEEN 4 AND 3 + :role_sample;

INSERT INTO seed_dialog_roles
SELECT 'background', a.id, b.id, pg_temp.dialog_id(a.id, b.id)
FROM background AS bg
JOIN participants AS a ON a.n = bg.a
JOIN participants AS b ON b.n = bg.b
WHERE bg.dialog % (:background_dialogs / :role_sample) = 0;

COMMIT;

ANALYZE dialog_messages;

\echo '--- итог'
SELECT count(*) AS messages, count(DISTINCT dialog_id) AS dialogs FROM dialog_messages;
SELECT role, count(*) AS pairs FROM seed_dialog_roles GROUP BY role ORDER BY role;
