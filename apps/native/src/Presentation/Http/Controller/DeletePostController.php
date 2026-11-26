<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\DeletePost\DeletePost;
use SocialNetwork\Core\Application\DeletePost\DeletePostCommand;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class DeletePostController implements Controller
{
    public function __construct(private RequestAuthenticator $authenticator, private DeletePost $deletePost)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $actor = $this->authenticator->actor($request);

        $this->deletePost->handle(new DeletePostCommand($actor, $routeParameters['id']));

        return JsonResponse::noContent();
    }
}
