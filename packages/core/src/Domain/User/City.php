<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

final readonly class City
{
    private const int MAX_LENGTH = 100;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        return new self(RequiredText::normalize($value, 'city', self::MAX_LENGTH));
    }
}
