<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Dialog;

use SocialNetwork\Core\Domain\User\UserId;

/**
 * Сообщение диалога. Диалог определяется участниками, отдельно его не передают.
 */
final readonly class Message
{
    public DialogId $dialogId;

    /**
     * @throws InvalidDialogData если отправитель и получатель совпадают
     */
    private function __construct(
        public MessageId $id,
        public UserId $senderId,
        public UserId $recipientId,
        public MessageText $text,
        public \DateTimeImmutable $sentAt,
    ) {
        $this->dialogId = DialogId::between($senderId, $recipientId, 'recipientId');
    }

    /**
     * Новое сообщение.
     *
     * @throws InvalidDialogData если отправитель пишет самому себе (field = recipientId)
     */
    public static function send(
        MessageId $id,
        UserId $senderId,
        UserId $recipientId,
        MessageText $text,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $senderId, $recipientId, $text, $now);
    }

    /**
     * Восстановление уже существующего сообщения из хранилища. Используется репозиториями.
     *
     * @throws InvalidDialogData если отправитель и получатель совпадают
     */
    public static function reconstitute(
        MessageId $id,
        UserId $senderId,
        UserId $recipientId,
        MessageText $text,
        \DateTimeImmutable $sentAt,
    ): self {
        return new self($id, $senderId, $recipientId, $text, $sentAt);
    }
}
