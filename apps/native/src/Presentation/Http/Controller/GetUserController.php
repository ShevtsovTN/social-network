<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetUser\GetUser;
use SocialNetwork\Core\Application\GetUser\GetUserQuery;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class GetUserController implements Controller
{
    public function __construct(private GetUser $getUser)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $user = $this->getUser->handle(new GetUserQuery($routeParameters['id']));

        return new JsonResponse(200, [
            'id' => $user->id->value,
            'firstName' => $user->firstName->value,
            'lastName' => $user->lastName->value,
            'birthDate' => $user->birthDate->toString(),
            'gender' => $user->gender->value,
            'interests' => $user->interests->values,
            'city' => $user->city->value,
        ]);
    }
}
