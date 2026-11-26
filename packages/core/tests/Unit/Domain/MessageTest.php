<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;
use SocialNetwork\Core\Domain\Dialog\Message;
use SocialNetwork\Core\Domain\Dialog\MessageText;
use SocialNetwork\CoreContract\MessageFixture;

final class MessageTest extends TestCase
{
    public function testSentMessageBelongsToTheDialogOfItsParticipants(): void
    {
        $now = new \DateTimeImmutable('2026-11-26 12:00:00.123456', new \DateTimeZone('UTC'));

        $message = Message::send(
            MessageFixture::messageId(1),
            MessageFixture::userId(2),
            MessageFixture::userId(1),
            MessageText::fromString('Привет'),
            $now,
        );

        self::assertTrue($message->dialogId->equals(MessageFixture::dialogId(1, 2)));
        self::assertTrue($message->senderId->equals(MessageFixture::userId(2)));
        self::assertTrue($message->recipientId->equals(MessageFixture::userId(1)));
        self::assertSame('Привет', $message->text->value);
        self::assertSame($now, $message->sentAt);
    }

    public function testCannotBeSentToYourself(): void
    {
        try {
            Message::send(
                MessageFixture::messageId(1),
                MessageFixture::userId(1),
                MessageFixture::userId(1),
                MessageText::fromString('Привет'),
                new \DateTimeImmutable(),
            );
            self::fail('InvalidDialogData expected.');
        } catch (InvalidDialogData $exception) {
            self::assertSame('recipientId', $exception->field);
        }
    }
}
