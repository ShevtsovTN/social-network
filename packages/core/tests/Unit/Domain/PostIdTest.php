<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostId;

final class PostIdTest extends TestCase
{
    public function testNormalizesToLowerCase(): void
    {
        $id = PostId::fromString('1D535FD6-7521-4CB1-AA6D-031BE7123C4D');

        self::assertSame('1d535fd6-7521-4cb1-aa6d-031be7123c4d', $id->value);
    }

    public function testEqualsIgnoresCase(): void
    {
        self::assertTrue(PostId::fromString('1d535fd6-7521-4cb1-aa6d-031be7123c4d')->equals(PostId::fromString('1D535FD6-7521-4CB1-AA6D-031BE7123C4D')));
        self::assertFalse(PostId::fromString('1d535fd6-7521-4cb1-aa6d-031be7123c4d')->equals(PostId::fromString('2d535fd6-7521-4cb1-aa6d-031be7123c4d')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'too short' => ['1d535fd6-7521-4cb1-aa6d-031be7123c4'];
        yield 'non hex digit' => ['1d535fd6-7521-4cb1-aa6d-031be7123c4g'];
        yield 'trailing line break' => ["1d535fd6-7521-4cb1-aa6d-031be7123c4d\n"];
        yield 'surrounding spaces' => [' 1d535fd6-7521-4cb1-aa6d-031be7123c4d '];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidIdWithFieldId(string $value): void
    {
        try {
            PostId::fromString($value);
            self::fail('InvalidPostData expected.');
        } catch (InvalidPostData $exception) {
            self::assertSame('id', $exception->field);
        }
    }
}
