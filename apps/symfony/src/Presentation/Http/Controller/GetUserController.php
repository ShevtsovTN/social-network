<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetUser\GetUser;
use SocialNetwork\Core\Application\GetUser\GetUserQuery;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
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

        return new ApiJsonResponse(200, [
            'id' => $user->id->value,
            'firstName' => $user->firstName->value,
            'lastName' => $user->lastName->value,
            'birthDate' => $user->birthDate->toString(),
            'gender' => $user->gender->value,
            'interests' => $user->interests->values,
            'city' => $user->city->value,
        ]);
    }
}
