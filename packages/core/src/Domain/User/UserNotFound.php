<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

use SocialNetwork\Core\Domain\DomainException;

final class UserNotFound extends DomainException
{
    public static function withId(UserId $id): self
    {
        return new self(sprintf('User %s was not found.', $id->value));
    }
}
