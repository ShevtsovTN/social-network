<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\RegisterUser\RegisterUser;
use SocialNetwork\Core\Application\RegisterUser\RegisterUserCommand;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\JsonBody;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class RegisterUserController
{
    public function __construct(private RegisterUser $registerUser)
    {
    }

    #[Route('/user/register', methods: ['POST'])]
    public function __invoke(Request $request): ApiJsonResponse
    {
        $body = JsonBody::fromRequest($request);

        $userId = $this->registerUser->handle(new RegisterUserCommand(
            $body->string('firstName'),
            $body->string('lastName'),
            $body->string('birthDate'),
            $body->string('gender'),
            $body->stringList('interests'),
            $body->string('city'),
            $body->string('password'),
        ));

        return new ApiJsonResponse(201, ['id' => $userId->value]);
    }
}
