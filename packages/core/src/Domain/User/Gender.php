<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw InvalidUserData::forField('gender', 'gender must be one of: male, female, other.');
    }
}
