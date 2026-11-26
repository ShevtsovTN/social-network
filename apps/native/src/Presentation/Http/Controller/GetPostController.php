<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetPost\GetPost;
use SocialNetwork\Core\Application\GetPost\GetPostQuery;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;
use SocialNetwork\NativeApp\Presentation\Http\Response\PostPayload;

/**
 * Публичный эндпоинт: авторизация не требуется (так в контракте).
 */
final readonly class GetPostController implements Controller
{
    public function __construct(private GetPost $getPost)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $post = $this->getPost->handle(new GetPostQuery($routeParameters['id']));

        return new JsonResponse(200, PostPayload::fromPost($post));
    }
}
