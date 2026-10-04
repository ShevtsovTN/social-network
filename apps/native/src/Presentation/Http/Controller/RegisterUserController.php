<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\RegisterUser\RegisterUser;
use SocialNetwork\Core\Application\RegisterUser\RegisterUserCommand;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class RegisterUserController implements Controller
{
    public function __construct(private RegisterUser $registerUser)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $body = $request->jsonBody();

        $userId = $this->registerUser->handle(new RegisterUserCommand(
            $body->string('firstName'),
            $body->string('lastName'),
            $body->string('birthDate'),
            $body->string('gender'),
            $body->stringList('interests'),
            $body->string('city'),
            $body->string('password'),
        ));

        return new JsonResponse(201, ['id' => $userId->value]);
    }
}
