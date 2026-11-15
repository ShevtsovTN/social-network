<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\Friend\InvalidFriendship;
use SocialNetwork\CoreContract\FeedFixture;

final class FriendshipTest extends TestCase
{
    public function testKeepsTheDirection(): void
    {
        $friendship = Friendship::between(FeedFixture::userId(1), FeedFixture::userId(2));

        self::assertSame(FeedFixture::userId(1)->value, $friendship->userId->value);
        self::assertSame(FeedFixture::userId(2)->value, $friendship->friendId->value);
    }

    public function testRejectsFriendshipWithYourself(): void
    {
        try {
            Friendship::between(FeedFixture::userId(1), FeedFixture::userId(1));
            self::fail('InvalidFriendship expected.');
        } catch (InvalidFriendship $exception) {
            self::assertSame('friendId', $exception->field);
        }
    }
}
