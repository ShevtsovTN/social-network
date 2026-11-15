<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\DeletePost\DeletePost;
use SocialNetwork\Core\Application\DeletePost\DeletePostCommand;
use SocialNetwork\SymfonyApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\NoContentResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class DeletePostController
{
    public function __construct(private RequestAuthenticator $authenticator, private DeletePost $deletePost)
    {
    }

    // Контракт (docs/openapi.json): удаление через PUT, а не DELETE, ответ 204.
    #[Route('/post/delete/{id}', methods: ['PUT'])]
    public function __invoke(Request $request, string $id): NoContentResponse
    {
        $actor = $this->authenticator->actor($request);

        $this->deletePost->handle(new DeletePostCommand($actor, $id));

        return new NoContentResponse();
    }
}
