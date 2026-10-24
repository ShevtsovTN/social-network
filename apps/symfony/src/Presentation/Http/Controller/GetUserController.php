<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetUser\GetUser;
use SocialNetwork\Core\Application\GetUser\GetUserQuery;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\UserPayload;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class GetUserController
{
    public function __construct(private GetUser $getUser)
    {
    }

    #[Route('/user/get/{id}', methods: ['GET'])]
    public function __invoke(string $id): ApiJsonResponse
    {
        $user = $this->getUser->handle(new GetUserQuery($id));

        return new ApiJsonResponse(200, UserPayload::fromUser($user));
    }
}
