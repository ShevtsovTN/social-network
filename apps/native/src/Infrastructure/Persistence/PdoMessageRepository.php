<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use SocialNetwork\Core\Application\Port\MessageRepository;
use SocialNetwork\Core\Domain\Dialog\DialogId;
use SocialNetwork\Core\Domain\Dialog\DialogPageRequest;
use SocialNetwork\Core\Domain\Dialog\Message;
use SocialNetwork\Core\Domain\Dialog\MessageId;
use SocialNetwork\Core\Domain\Dialog\MessageText;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Сообщения в кластере Citus: подключение к координатору. О шардах репозиторий не знает, но каждый запрос задаёт
 * dialog_id (ключ шардирования), поэтому Citus выполняет его на одном воркере.
 */
final readonly class PdoMessageRepository implements MessageRepository
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private PDO $connection)
    {
    }

    public function add(Message $message): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO dialog_messages (dialog_id, id, sender_id, recipient_id, text, created_at)
             VALUES (:dialog_id, :id, :sender_id, :recipient_id, :text, :created_at)',
        );

        $statement->execute([
            'dialog_id' => $message->dialogId->value,
            'id' => $message->id->value,
            'sender_id' => $message->senderId->value,
            'recipient_id' => $message->recipientId->value,
            'text' => $message->text->value,
            'created_at' => $message->sentAt->format(self::TIMESTAMP_FORMAT),
        ]);
    }

    public function findPage(DialogId $dialogId, DialogPageRequest $page): array
    {
        // Два статических запроса, с курсором и без него. Оба читают первичный ключ (dialog_id, id) с конца:
        // стоимость страницы не зависит от того, как далеко прокручена история.
        $statement = $this->connection->prepare($page->before === null
            ? 'SELECT id, sender_id, recipient_id, text, created_at FROM dialog_messages
               WHERE dialog_id = :dialog_id
               ORDER BY id DESC LIMIT :limit'
            : 'SELECT id, sender_id, recipient_id, text, created_at FROM dialog_messages
               WHERE dialog_id = :dialog_id AND id < :before
               ORDER BY id DESC LIMIT :limit');

        $statement->bindValue('dialog_id', $dialogId->value);
        if ($page->before !== null) {
            $statement->bindValue('before', $page->before->value);
        }
        $statement->bindValue('limit', $page->limit, PDO::PARAM_INT);
        $statement->execute();

        // Выбраны самые новые, а страница отдаётся от старых к новым.
        return array_reverse(array_map(self::messageFromRow(...), $statement->fetchAll()));
    }

    /**
     * @param array<string, string> $row
     */
    private static function messageFromRow(array $row): Message
    {
        return Message::reconstitute(
            MessageId::fromString($row['id']),
            UserId::fromString($row['sender_id']),
            UserId::fromString($row['recipient_id']),
            MessageText::fromString($row['text']),
            new DateTimeImmutable($row['created_at']),
        );
    }
}
