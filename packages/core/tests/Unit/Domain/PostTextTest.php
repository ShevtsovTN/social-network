<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostText;

final class PostTextTest extends TestCase
{
    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Привет, мир', PostText::fromString("  \n Привет, мир \t ")->value);
    }

    public function testKeepsLineBreaksInside(): void
    {
        self::assertSame("Первая\n\nТретья", PostText::fromString("Первая\n\nТретья")->value);
    }

    public function testConvertsWindowsLineBreaks(): void
    {
        self::assertSame("Первая\nВторая", PostText::fromString("Первая\r\nВторая")->value);
    }

    public function testAcceptsTextOfMaximumLengthCountedInCharacters(): void
    {
        $text = str_repeat('я', PostText::MAX_LENGTH);

        self::assertSame($text, PostText::fromString($text)->value);
    }

    public function testKeepsUnicodeAndQuotes(): void
    {
        $text = "Эмодзи 🙂, «ёлочки», \"кавычки\" и '; DROP TABLE posts; --";

        self::assertSame($text, PostText::fromString($text)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTexts(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'only line breaks' => ["\n\r\n\t "];
        yield 'too long' => [str_repeat('a', PostText::MAX_LENGTH + 1)];
        yield 'too long in characters, not bytes' => [str_repeat('я', PostText::MAX_LENGTH + 1)];
        yield 'invalid utf-8' => ["Текст \xC3\x28"];
        yield 'null byte inside' => ["до\0после"];
        yield 'tab inside' => ["до\tпосле"];
        yield 'lone carriage return inside' => ["до\rпосле"];
        yield 'escape inside' => ["до\x1Bпосле"];
    }

    #[DataProvider('invalidTexts')]
    public function testRejectsInvalidTextWithFieldText(string $value): void
    {
        try {
            PostText::fromString($value);
            self::fail('InvalidPostData expected.');
        } catch (InvalidPostData $exception) {
            self::assertSame('text', $exception->field);
        }
    }
}
