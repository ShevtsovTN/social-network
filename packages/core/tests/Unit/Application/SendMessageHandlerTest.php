<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\SendMessage\SendMessageCommand;
use SocialNetwork\Core\Application\SendMessage\SendMessageHandler;
use SocialNetwork\Core\Domain\Dialog\DialogPageRequest;
use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;
use SocialNetwork\Core\Domain\Dialog\Message;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserNotFound;
use SocialNetwork\Core\Tests\Unit\Fake\FixedClock;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryMessageRepository;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryUserRepository;
use SocialNetwork\Core\Tests\Unit\Fake\SequentialMessageIdGenerator;
use SocialNetwork\CoreContract\MessageFixture;
use SocialNetwork\CoreContract\UserFixture;

final class SendMessageHandlerTest extends TestCase
{
    private InMemoryMessageRepository $messages;
    private SendMessageHandler $handler;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $users = new InMemoryUserRepository();
        $users->save(UserFixture::create(MessageFixture::userId(1)->value));
        $users->save(UserFixture::create(MessageFixture::userId(2)->value));

        $this->messages = new InMemoryMessageRepository();
        $this->now = new \DateTimeImmutable('2026-11-26 12:00:00.123456', new \DateTimeZone('UTC'));
        $this->handler = new SendMessageHandler(
            $users,
            $this->messages,
            new SequentialMessageIdGenerator(),
            new FixedClock($this->now),
        );
    }

    public function testStoresTheMessageFromTheActorToTheRecipientWithCurrentTime(): void
    {
        $this->send(1, MessageFixture::userId(2)->value, "  Привет \n");

        $dialog = $this->dialog();
        self::assertCount(1, $dialog);
        self::assertTrue($dialog[0]->senderId->equals(MessageFixture::userId(1)));
        self::assertTrue($dialog[0]->recipientId->equals(MessageFixture::userId(2)));
        self::assertSame('Привет', $dialog[0]->text->value);
        self::assertEquals($this->now, $dialog[0]->sentAt);
    }

    public function testEveryCallCreatesANewMessageInSendingOrder(): void
    {
        $this->send(1, MessageFixture::userId(2)->value, 'Повтор');
        $this->send(1, MessageFixture::userId(2)->value, 'Повтор');
        $this->send(2, MessageFixture::userId(1)->value, 'Ответ');

        self::assertSame(
            ['Повтор', 'Повтор', 'Ответ'],
            array_map(static fn (Message $message): string => $message->text->value, $this->dialog()),
        );
    }

    public function testRecipientIdIsCaseInsensitive(): void
    {
        $this->send(1, strtoupper(MessageFixture::userId(2)->value), 'Привет');

        self::assertCount(1, $this->dialog());
    }

    public function testCannotWriteToAnUnknownUser(): void
    {
        $this->expectException(UserNotFound::class);

        try {
            $this->send(1, MessageFixture::userId(99)->value, 'Привет');
        } finally {
            self::assertSame(0, $this->messages->count());
        }
    }

    public function testCannotWriteToYourself(): void
    {
        $this->assertRejected(InvalidDialogData::class, 'recipientId', MessageFixture::userId(1)->value, 'Привет');
    }

    public function testMalformedRecipientIdIsReportedAsRecipientId(): void
    {
        $this->assertRejected(InvalidUserData::class, 'recipientId', 'not-a-uuid', 'Привет');
    }

    public function testInvalidTextIsReported(): void
    {
        $this->assertRejected(InvalidDialogData::class, 'text', MessageFixture::userId(2)->value, '   ');
    }

    public function testInvalidTextIsReportedEvenForAnUnknownRecipient(): void
    {
        $this->assertRejected(InvalidDialogData::class, 'text', MessageFixture::userId(99)->value, '');
    }

    /**
     * @param class-string<InvalidDialogData|InvalidUserData> $exceptionClass
     */
    private function assertRejected(string $exceptionClass, string $field, string $recipientId, string $text): void
    {
        try {
            $this->send(1, $recipientId, $text);
            self::fail($exceptionClass . ' expected.');
        } catch (InvalidDialogData|InvalidUserData $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($field, $exception->field);
        }

        self::assertSame(0, $this->messages->count());
    }

    private function send(int $actor, string $recipientId, string $text): void
    {
        $this->handler->handle(new SendMessageCommand(MessageFixture::userId($actor), $recipientId, $text));
    }

    /**
     * @return list<Message>
     */
    private function dialog(): array
    {
        return $this->messages->findPage(MessageFixture::dialogId(1, 2), DialogPageRequest::fromInput(null, null));
    }
}
