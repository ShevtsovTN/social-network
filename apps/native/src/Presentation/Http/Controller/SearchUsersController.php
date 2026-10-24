<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\SearchUsers\SearchUsers;
use SocialNetwork\Core\Application\SearchUsers\SearchUsersQuery;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;
use SocialNetwork\NativeApp\Presentation\Http\Response\UserPayload;

final readonly class SearchUsersController implements Controller
{
    public function __construct(private SearchUsers $searchUsers)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $query = $request->query();
        $users = $this->searchUsers->handle(new SearchUsersQuery(
            $query->string('first_name', 'firstName'),
            $query->string('last_name', 'lastName'),
        ));

        return new JsonResponse(200, array_map(UserPayload::fromUser(...), $users));
    }
}
