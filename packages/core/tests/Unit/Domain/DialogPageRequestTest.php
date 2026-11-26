<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Dialog\DialogPageRequest;
use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;

final class DialogPageRequestTest extends TestCase
{
    public function testDefaultsToTheNewestFiftyMessages(): void
    {
        $page = DialogPageRequest::fromInput(null, null);

        self::assertSame(50, $page->limit);
        self::assertNull($page->before);
    }

    public function testAcceptsTheBoundsOfLimitAndACursor(): void
    {
        self::assertSame(1, DialogPageRequest::fromInput('1', null)->limit);
        self::assertSame(100, DialogPageRequest::fromInput('100', null)->limit);
        self::assertSame(
            '0199c000-0000-7000-8000-00000000000a',
            DialogPageRequest::fromInput(null, '0199C000-0000-7000-8000-00000000000A')->before?->value,
        );
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'limit пустой' => ['', null, 'limit'];
        yield 'limit ноль' => ['0', null, 'limit'];
        yield 'limit выше максимума' => ['101', null, 'limit'];
        yield 'limit отрицательный' => ['-1', null, 'limit'];
        yield 'limit дробный' => ['1.5', null, 'limit'];
        yield 'limit с пробелом' => [' 5', null, 'limit'];
        yield 'limit с переводом строки' => ["5\n", null, 'limit'];
        yield 'limit не помещается в int' => [str_repeat('9', 30), null, 'limit'];
        yield 'before пустой' => [null, '', 'before'];
        yield 'before не UUID' => [null, 'not-a-uuid', 'before'];
    }

    #[DataProvider('invalidInput')]
    public function testRejectsInvalidInput(?string $limit, ?string $before, string $field): void
    {
        try {
            DialogPageRequest::fromInput($limit, $before);
            self::fail('InvalidDialogData expected.');
        } catch (InvalidDialogData $exception) {
            self::assertSame($field, $exception->field);
        }
    }
}
