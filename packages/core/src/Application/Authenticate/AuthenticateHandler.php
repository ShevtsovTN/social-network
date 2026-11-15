<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Authenticate;

use SocialNetwork\Core\Application\Port\TokenVerifier;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class AuthenticateHandler implements Authenticate
{
    public function __construct(private TokenVerifier $tokenVerifier)
    {
    }

    public function handle(AuthenticateQuery $query): UserId
    {
        return $this->tokenVerifier->verify($query->token) ?? throw Unauthenticated::create();
    }
}
