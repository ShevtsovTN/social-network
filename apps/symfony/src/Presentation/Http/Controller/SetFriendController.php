<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\AddFriend\AddFriend;
use SocialNetwork\Core\Application\AddFriend\AddFriendCommand;
use SocialNetwork\SymfonyApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\NoContentResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class SetFriendController
{
    public function __construct(private RequestAuthenticator $authenticator, private AddFriend $addFriend)
    {
    }

    // Контракт (docs/openapi.json): изменение через PUT без тела, путь /friend/set, а не /friend/add, ответ 204.
    #[Route('/friend/set/{userId}', methods: ['PUT'])]
    public function __invoke(Request $request, string $userId): NoContentResponse
    {
        $actor = $this->authenticator->actor($request);

        $this->addFriend->handle(new AddFriendCommand($actor, $userId));

        return new NoContentResponse();
    }
}
