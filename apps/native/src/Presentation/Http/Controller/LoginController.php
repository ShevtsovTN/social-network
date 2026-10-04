<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\Login\InvalidCredentials;
use SocialNetwork\Core\Application\Login\Login;
use SocialNetwork\Core\Application\Login\LoginCommand;
use SocialNetwork\NativeApp\Presentation\Http\Request\MalformedRequest;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class LoginController implements Controller
{
    public function __construct(private Login $login)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        // Контракт: любая причина отказа входа это 401, в том числе битый запрос.
        try {
            $body = $request->jsonBody();
            $command = new LoginCommand($body->string('userId'), $body->string('password'));
        } catch (MalformedRequest) {
            throw InvalidCredentials::create();
        }

        return new JsonResponse(200, ['token' => $this->login->handle($command)]);
    }
}
