<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Post;

/**
 * Текст поста: валидный UTF-8, без пробелов по краям, не пустой, не длиннее 2000 символов.
 * Управляющие символы запрещены, кроме перевода строки; «\r\n» приводится к «\n».
 */
final readonly class PostText
{
    public const int MAX_LENGTH = 2000;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw InvalidPostData::forField('text', 'text must be valid UTF-8.');
        }

        $normalized = trim(str_replace("\r\n", "\n", $value));

        if ($normalized === '') {
            throw InvalidPostData::forField('text', 'text must not be empty.');
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidPostData::forField('text', sprintf('text must be at most %d characters long.', self::MAX_LENGTH));
        }

        // (?!\n): любой управляющий символ, кроме перевода строки.
        if (preg_match('/(?!\n)\p{Cc}/u', $normalized) === 1) {
            throw InvalidPostData::forField('text', 'text must not contain control characters other than a line break.');
        }

        return new self($normalized);
    }
}
