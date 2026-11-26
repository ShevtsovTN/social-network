<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\GetDialog\GetDialogHandler;
use SocialNetwork\Core\Application\GetDialog\GetDialogQuery;
use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;
use SocialNetwork\Core\Domain\Dialog\Message;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryMessageRepository;
use SocialNetwork\CoreContract\MessageFixture;

final class GetDialogHandlerTest extends TestCase
{
    private GetDialogHandler $handler;

    protected function setUp(): void
    {
        $messages = new InMemoryMessageRepository();
        $messages->add(MessageFixture::message(1, 1, 2));
        $messages->add(MessageFixture::message(2, 2, 1));
        $messages->add(MessageFixture::message(3, 1, 2));
        $messages->add(MessageFixture::message(4, 3, 2));

        $this->handler = new GetDialogHandler($messages);
    }

    public function testBothParticipantsSeeTheSameDialog(): void
    {
        self::assertSame(MessageFixture::expectedIds(1, 2, 3), MessageFixture::ids($this->dialog(1, 2)));
        self::assertSame(MessageFixture::expectedIds(1, 2, 3), MessageFixture::ids($this->dialog(2, 1)));
    }

    public function testAnotherUserSeesOnlyTheirOwnDialog(): void
    {
        self::assertSame(MessageFixture::expectedIds(4), MessageFixture::ids($this->dialog(3, 2)));
        self::assertSame([], $this->dialog(3, 1));
    }

    public function testDialogWithAnUnknownUserIsEmpty(): void
    {
        self::assertSame([], $this->dialog(1, 99));
    }

    public function testPassesLimitAndCursorToTheRepository(): void
    {
        self::assertSame(MessageFixture::expectedIds(3), MessageFixture::ids($this->dialog(1, 2, '1')));
        self::assertSame(
            MessageFixture::expectedIds(1, 2),
            MessageFixture::ids($this->dialog(1, 2, '2', MessageFixture::messageId(3)->value)),
        );
    }

    public function testCannotReadADialogWithYourself(): void
    {
        $this->assertRejected(InvalidDialogData::class, 'companionId', MessageFixture::userId(1)->value);
    }

    public function testMalformedCompanionIdIsReportedAsCompanionId(): void
    {
        $this->assertRejected(InvalidUserData::class, 'companionId', 'not-a-uuid');
    }

    public function testInvalidPageParametersAreReported(): void
    {
        $companion = MessageFixture::userId(2)->value;

        $this->assertRejected(InvalidDialogData::class, 'limit', $companion, '0');
        $this->assertRejected(InvalidDialogData::class, 'before', $companion, null, 'not-a-uuid');
    }

    /**
     * @param class-string<InvalidDialogData|InvalidUserData> $exceptionClass
     */
    private function assertRejected(
        string $exceptionClass,
        string $field,
        string $companionId,
        ?string $limit = null,
        ?string $before = null,
    ): void {
        try {
            $this->handler->handle(new GetDialogQuery(MessageFixture::userId(1), $companionId, $limit, $before));
            self::fail($exceptionClass . ' expected.');
        } catch (InvalidDialogData|InvalidUserData $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($field, $exception->field);
        }
    }

    /**
     * @return list<Message>
     */
    private function dialog(int $actor, int $companion, ?string $limit = null, ?string $before = null): array
    {
        return $this->handler->handle(new GetDialogQuery(
            MessageFixture::userId($actor),
            MessageFixture::userId($companion)->value,
            $limit,
            $before,
        ));
    }
}
