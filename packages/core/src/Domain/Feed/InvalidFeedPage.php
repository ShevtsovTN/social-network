<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Feed;

use SocialNetwork\Core\Domain\DomainException;

/**
 * Неверные параметры страницы ленты. Поле называется так же, как параметр запроса (offset, limit).
 */
final class InvalidFeedPage extends DomainException
{
    private function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function forField(string $field, string $message): self
    {
        return new self($field, $message);
    }
}
