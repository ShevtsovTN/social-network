<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\SearchUsers\SearchUsers;
use SocialNetwork\Core\Application\SearchUsers\SearchUsersQuery;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\QueryParameters;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\UserPayload;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class SearchUsersController
{
    public function __construct(private SearchUsers $searchUsers)
    {
    }

    #[Route('/user/search', methods: ['GET'])]
    public function __invoke(Request $request): ApiJsonResponse
    {
        $query = QueryParameters::fromRequest($request);
        $users = $this->searchUsers->handle(new SearchUsersQuery(
            $query->string('first_name', 'firstName'),
            $query->string('last_name', 'lastName'),
        ));

        return new ApiJsonResponse(200, array_map(UserPayload::fromUser(...), $users));
    }
}
