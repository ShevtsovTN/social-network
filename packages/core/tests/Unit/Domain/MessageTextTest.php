<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;
use SocialNetwork\Core\Domain\Dialog\MessageText;

final class MessageTextTest extends TestCase
{
    public function testTrimsAndNormalizesLineBreaks(): void
    {
        self::assertSame("Первая\nВторая", MessageText::fromString("  Первая\r\nВторая \n")->value);
    }

    public function testAcceptsTheMaximumLengthInCharactersNotBytes(): void
    {
        $text = str_repeat('я', 2000);

        self::assertSame($text, MessageText::fromString($text)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTexts(): iterable
    {
        yield 'пустая строка' => [''];
        yield 'только пробелы и переводы строк' => [" \r\n \n "];
        yield 'длиннее 2000 символов' => [str_repeat('я', 2001)];
        yield 'не UTF-8' => ["\xB1\x31"];
        yield 'нулевой байт' => ["a\0b"];
        yield 'табуляция внутри' => ["a\tb"];
        yield 'одиночный возврат каретки' => ["a\rb"];
        yield 'управляющий символ из блока C1' => ["a\u{85}b"];
    }

    #[DataProvider('invalidTexts')]
    public function testRejectsInvalidText(string $value): void
    {
        try {
            MessageText::fromString($value);
            self::fail('InvalidDialogData expected.');
        } catch (InvalidDialogData $exception) {
            self::assertSame('text', $exception->field);
        }
    }
}
