<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\AddFriend\AddFriend;
use SocialNetwork\Core\Application\AddFriend\AddFriendCommand;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class SetFriendController implements Controller
{
    public function __construct(private RequestAuthenticator $authenticator, private AddFriend $addFriend)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $actor = $this->authenticator->actor($request);

        $this->addFriend->handle(new AddFriendCommand($actor, $routeParameters['userId']));

        return JsonResponse::noContent();
    }
}
