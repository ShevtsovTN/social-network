<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Auth;

use SocialNetwork\Core\Application\Authenticate\Authenticate;
use SocialNetwork\Core\Application\Authenticate\AuthenticateQuery;
use SocialNetwork\Core\Application\Authenticate\Unauthenticated;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\BearerToken;
use Symfony\Component\HttpFoundation\Request;

/**
 * Кто делает запрос: достаёт токен из заголовка и отдаёт его use case Authenticate.
 * Проверка токена целиком в core, здесь только HTTP-часть.
 */
final readonly class RequestAuthenticator
{
    public function __construct(private Authenticate $authenticate)
    {
    }

    /**
     * @throws Unauthenticated
     */
    public function actor(Request $request): UserId
    {
        return $this->authenticate->handle(new AuthenticateQuery(BearerToken::fromRequest($request)));
    }
}
