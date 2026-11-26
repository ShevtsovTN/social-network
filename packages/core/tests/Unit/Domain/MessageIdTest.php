<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;
use SocialNetwork\Core\Domain\Dialog\MessageId;

final class MessageIdTest extends TestCase
{
    public function testNormalizesToLowerCase(): void
    {
        $id = MessageId::fromString('0199C000-0000-7000-8000-00000000000A');

        self::assertSame('0199c000-0000-7000-8000-00000000000a', $id->value);
    }

    public function testRejectsAMalformedValueUnderTheGivenField(): void
    {
        foreach (['', 'abc', "0199c000-0000-7000-8000-00000000000a\n"] as $value) {
            try {
                MessageId::fromString($value, 'before');
                self::fail('InvalidDialogData expected.');
            } catch (InvalidDialogData $exception) {
                self::assertSame('before', $exception->field);
            }
        }
    }
}
