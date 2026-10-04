<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\Login\InvalidCredentials;
use SocialNetwork\Core\Application\Login\Login;
use SocialNetwork\Core\Application\Login\LoginCommand;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\JsonBody;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\MalformedRequest;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class LoginController
{
    public function __construct(private Login $login)
    {
    }

    #[Route('/login', methods: ['POST'])]
    public function __invoke(Request $request): ApiJsonResponse
    {
        // Контракт: любая причина отказа входа это 401, в том числе битый запрос.
        try {
            $body = JsonBody::fromRequest($request);
            $command = new LoginCommand($body->string('userId'), $body->string('password'));
        } catch (MalformedRequest) {
            throw InvalidCredentials::create();
        }

        return new ApiJsonResponse(200, ['token' => $this->login->handle($command)]);
    }
}
