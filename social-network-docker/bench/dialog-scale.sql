-- Транзакция pgbench для замера записи (make bench-dialog-scale): одна вставка сообщения в случайный диалог из 100 тысяч.
\set dialog random(1, 100000)
INSERT INTO dialog_messages (dialog_id, id, sender_id, recipient_id, text) VALUES (md5((:dialog)::text)::uuid, gen_random_uuid(), '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', 'Сообщение для замера записи');
