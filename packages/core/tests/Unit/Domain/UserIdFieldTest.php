<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserId;

final class UserIdFieldTest extends TestCase
{
    public function testReportsFieldIdByDefault(): void
    {
        try {
            UserId::fromString('nope');
            self::fail('InvalidUserData expected.');
        } catch (InvalidUserData $exception) {
            self::assertSame('id', $exception->field);
            self::assertSame('User id must be a valid UUID.', $exception->getMessage());
        }
    }

    public function testReportsTheGivenField(): void
    {
        try {
            UserId::fromString('nope', 'friendId');
            self::fail('InvalidUserData expected.');
        } catch (InvalidUserData $exception) {
            self::assertSame('friendId', $exception->field);
            self::assertSame('friendId must be a valid UUID.', $exception->getMessage());
        }
    }

    public function testValidIdIsAcceptedWhateverTheField(): void
    {
        self::assertSame(
            '11111111-1111-4111-8111-111111111111',
            UserId::fromString('11111111-1111-4111-8111-111111111111', 'friendId')->value,
        );
    }
}
