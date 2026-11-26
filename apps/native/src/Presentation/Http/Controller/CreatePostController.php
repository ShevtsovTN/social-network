<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\CreatePost\CreatePost;
use SocialNetwork\Core\Application\CreatePost\CreatePostCommand;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class CreatePostController implements Controller
{
    public function __construct(private RequestAuthenticator $authenticator, private CreatePost $createPost)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $actor = $this->authenticator->actor($request);

        $postId = $this->createPost->handle(new CreatePostCommand($actor, $request->jsonBody()->string('text')));

        return new JsonResponse(201, ['id' => $postId->value]);
    }
}
