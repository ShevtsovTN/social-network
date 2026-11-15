<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetPost\GetPost;
use SocialNetwork\Core\Application\GetPost\GetPostQuery;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\PostPayload;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичный эндпоинт: авторизация не требуется (так в контракте).
 */
#[AsController]
final readonly class GetPostController
{
    public function __construct(private GetPost $getPost)
    {
    }

    #[Route('/post/get/{id}', methods: ['GET'])]
    public function __invoke(string $id): ApiJsonResponse
    {
        $post = $this->getPost->handle(new GetPostQuery($id));

        return new ApiJsonResponse(200, PostPayload::fromPost($post));
    }
}
