<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\RemoveFriend\RemoveFriend;
use SocialNetwork\Core\Application\RemoveFriend\RemoveFriendCommand;
use SocialNetwork\SymfonyApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\NoContentResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class DeleteFriendController
{
    public function __construct(private RequestAuthenticator $authenticator, private RemoveFriend $removeFriend)
    {
    }

    // Контракт (docs/openapi.json): удаление через PUT, а не DELETE, ответ 204.
    #[Route('/friend/delete/{userId}', methods: ['PUT'])]
    public function __invoke(Request $request, string $userId): NoContentResponse
    {
        $actor = $this->authenticator->actor($request);

        $this->removeFriend->handle(new RemoveFriendCommand($actor, $userId));

        return new NoContentResponse();
    }
}
