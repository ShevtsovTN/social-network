<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\CreatePost\CreatePost;
use SocialNetwork\Core\Application\CreatePost\CreatePostCommand;
use SocialNetwork\SymfonyApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\JsonBody;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class CreatePostController
{
    public function __construct(private RequestAuthenticator $authenticator, private CreatePost $createPost)
    {
    }

    #[Route('/post/create', methods: ['POST'])]
    public function __invoke(Request $request): ApiJsonResponse
    {
        $actor = $this->authenticator->actor($request);

        $postId = $this->createPost->handle(new CreatePostCommand($actor, JsonBody::fromRequest($request)->string('text')));

        return new ApiJsonResponse(201, ['id' => $postId->value]);
    }
}
