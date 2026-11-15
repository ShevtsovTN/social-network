<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\RemoveFriend\RemoveFriend;
use SocialNetwork\Core\Application\RemoveFriend\RemoveFriendCommand;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class DeleteFriendController implements Controller
{
    public function __construct(private RequestAuthenticator $authenticator, private RemoveFriend $removeFriend)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $actor = $this->authenticator->actor($request);

        $this->removeFriend->handle(new RemoveFriendCommand($actor, $routeParameters['userId']));

        return JsonResponse::noContent();
    }
}
