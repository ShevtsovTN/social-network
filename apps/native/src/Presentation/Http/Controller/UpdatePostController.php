<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\UpdatePost\UpdatePost;
use SocialNetwork\Core\Application\UpdatePost\UpdatePostCommand;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class UpdatePostController implements Controller
{
    public function __construct(private RequestAuthenticator $authenticator, private UpdatePost $updatePost)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $actor = $this->authenticator->actor($request);
        $body = $request->jsonBody();

        $this->updatePost->handle(new UpdatePostCommand($actor, $body->string('id'), $body->string('text')));

        return JsonResponse::noContent();
    }
}
