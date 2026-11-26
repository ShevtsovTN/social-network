<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\UpdatePost\UpdatePost;
use SocialNetwork\Core\Application\UpdatePost\UpdatePostCommand;
use SocialNetwork\SymfonyApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\JsonBody;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\NoContentResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class UpdatePostController
{
    public function __construct(private RequestAuthenticator $authenticator, private UpdatePost $updatePost)
    {
    }

    // Контракт (docs/openapi.json): PUT на коллекцию с id в теле, ответ 204.
    #[Route('/post/update', methods: ['PUT'])]
    public function __invoke(Request $request): NoContentResponse
    {
        $actor = $this->authenticator->actor($request);
        $body = JsonBody::fromRequest($request);

        $this->updatePost->handle(new UpdatePostCommand($actor, $body->string('id'), $body->string('text')));

        return new NoContentResponse();
    }
}
