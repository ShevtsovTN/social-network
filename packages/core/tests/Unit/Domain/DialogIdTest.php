<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Dialog\DialogId;
use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\CoreContract\MessageFixture;

final class DialogIdTest extends TestCase
{
    /**
     * Эталон посчитан независимо (SHA-256 строки «меньший:больший», версия 8). Он закреплён намеренно:
     * по DialogId сообщения лежат в шардах, и смена способа вычисления сделала бы записанные ненаходимыми.
     */
    public function testValueIsPinned(): void
    {
        self::assertSame('5bf65637-1159-8737-9ae3-acb27957e2e7', MessageFixture::dialogId(1, 2)->value);
    }

    public function testDoesNotDependOnTheOrderOfParticipants(): void
    {
        self::assertTrue(MessageFixture::dialogId(1, 2)->equals(MessageFixture::dialogId(2, 1)));
    }

    public function testDoesNotDependOnTheLetterCaseOfUserIds(): void
    {
        $upper = DialogId::between(
            UserId::fromString(strtoupper(MessageFixture::userId(1)->value)),
            MessageFixture::userId(2),
        );

        self::assertTrue($upper->equals(MessageFixture::dialogId(1, 2)));
    }

    public function testEveryPairHasItsOwnId(): void
    {
        $ids = [];

        foreach (range(1, 30) as $first) {
            foreach (range($first + 1, 31) as $second) {
                $ids[] = MessageFixture::dialogId($first, $second)->value;
            }
        }

        self::assertCount(count($ids), array_unique($ids));
    }

    public function testIsAVersion8Uuid(): void
    {
        foreach (range(2, 40) as $number) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
                MessageFixture::dialogId(1, $number)->value,
            );
        }
    }

    public function testRejectsADialogWithYourself(): void
    {
        try {
            DialogId::between(MessageFixture::userId(1), MessageFixture::userId(1));
            self::fail('InvalidDialogData expected.');
        } catch (InvalidDialogData $exception) {
            self::assertSame('companionId', $exception->field);
        }
    }
}
