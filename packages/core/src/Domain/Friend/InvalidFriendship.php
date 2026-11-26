<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Friend;

use SocialNetwork\Core\Domain\DomainException;

/**
 * Нарушено правило дружбы. Поле называется в терминах домена (friendId).
 */
final class InvalidFriendship extends DomainException
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
