<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\User\Interests;
use SocialNetwork\Core\Tests\Unit\Support\AssertsInvalidUserData;

final class InterestsTest extends TestCase
{
    use AssertsInvalidUserData;

    public function testTrimsSkipsEmptyAndKeepsOrder(): void
    {
        $interests = Interests::fromList(['  hiking ', '', '   ', 'chess']);

        self::assertSame(['hiking', 'chess'], $interests->values);
    }

    public function testRemovesDuplicatesIgnoringCase(): void
    {
        $interests = Interests::fromList(['Chess', 'chess', 'CHESS', 'Кофе', 'кофе']);

        self::assertSame(['Chess', 'Кофе'], $interests->values);
    }

    public function testEmptyListIsAllowed(): void
    {
        self::assertTrue(Interests::fromList([])->isEmpty());
        self::assertTrue(Interests::none()->isEmpty());
        self::assertFalse(Interests::fromList(['go'])->isEmpty());
    }

    public function testRejectsTooManyInterests(): void
    {
        $values = array_map(static fn (int $i): string => 'interest-' . $i, range(1, 21));

        $this->assertInvalidField('interests', static fn () => Interests::fromList($values));
    }

    public function testAcceptsExactlyTheMaximumNumberOfInterests(): void
    {
        $values = array_map(static fn (int $i): string => 'interest-' . $i, range(1, 20));

        self::assertCount(20, Interests::fromList($values)->values);
    }

    public function testRejectsTooLongInterest(): void
    {
        $this->assertInvalidField('interests', static fn () => Interests::fromList([str_repeat('a', 51)]));
    }

    public function testRejectsControlCharacters(): void
    {
        $this->assertInvalidField('interests', static fn () => Interests::fromList(["bad\x00value"]));
    }
}
