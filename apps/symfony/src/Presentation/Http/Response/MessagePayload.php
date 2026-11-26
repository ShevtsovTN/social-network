<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Response;

use SocialNetwork\Core\Domain\Dialog\Message;

/**
 * Сообщение в виде схемы DialogMessage из docs/openapi.json. Поле id добавлено к спецификации OTUS: это курсор
 * подгрузки истории. Чистая функция без состояния, создавать экземпляры не нужно.
 */
final class MessagePayload
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function fromMessage(Message $message): array
    {
        return [
            'id' => $message->id->value,
            'from' => $message->senderId->value,
            'to' => $message->recipientId->value,
            'text' => $message->text->value,
        ];
    }
}
