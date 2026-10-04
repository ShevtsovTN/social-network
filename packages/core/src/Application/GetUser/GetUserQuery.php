<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetUser;

final readonly class GetUserQuery
{
    public function __construct(public string $userId)
    {
    }
}
