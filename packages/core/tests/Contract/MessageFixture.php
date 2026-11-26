<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use SocialNetwork\Core\Domain\Dialog\DialogId;
use SocialNetwork\Core\Domain\Dialog\Message;
use SocialNetwork\Core\Domain\Dialog\MessageId;
use SocialNetwork\Core\Domain\Dialog\MessageText;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Участники и сообщения для тестов диалогов. Чем больше номер сообщения, тем больше его id (сообщение новее).
 * Доступен приложениям вместе с core.
 */
final class MessageFixture
{
    private const int BASE_TIMESTAMP = 1_800_000_000;

    private function __construct()
    {
    }

    public static function userId(int $number): UserId
    {
        return UserId::fromString(sprintf('c0000000-0000-4000-8000-%012d', $number));
    }

    public static function messageId(int $number): MessageId
    {
        return MessageId::fromString(sprintf('0199c000-0000-7000-8000-%012d', $number));
    }

    public static function dialogId(int $firstUser, int $secondUser): DialogId
    {
        return DialogId::between(self::userId($firstUser), self::userId($secondUser));
    }

    /**
     * @param int|null $sentAfterSeconds смещение времени отправки; null — по номеру сообщения
     */
    public static function message(
        int $number,
        int $senderNumber = 1,
        int $recipientNumber = 2,
        string $text = 'Текст сообщения',
        ?int $sentAfterSeconds = null,
        int $microseconds = 0,
    ): Message {
        $sentAt = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', self::BASE_TIMESTAMP + ($sentAfterSeconds ?? $number), $microseconds),
        );
        \assert($sentAt instanceof \DateTimeImmutable);

        return Message::reconstitute(
            self::messageId($number),
            self::userId($senderNumber),
            self::userId($recipientNumber),
            MessageText::fromString($text),
            $sentAt->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<string>
     */
    public static function ids(array $messages): array
    {
        return array_map(static fn (Message $message): string => $message->id->value, $messages);
    }

    /**
     * @return list<string> id сообщений с указанными номерами в указанном порядке
     */
    public static function expectedIds(int ...$numbers): array
    {
        return array_values(array_map(static fn (int $number): string => self::messageId($number)->value, $numbers));
    }
}
