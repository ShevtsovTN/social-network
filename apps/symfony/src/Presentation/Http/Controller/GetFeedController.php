<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetFeed\GetFeed;
use SocialNetwork\Core\Application\GetFeed\GetFeedQuery;
use SocialNetwork\SymfonyApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\QueryParameters;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\PostPayload;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class GetFeedController
{
    public function __construct(private RequestAuthenticator $authenticator, private GetFeed $getFeed)
    {
    }

    #[Route('/post/feed', methods: ['GET'])]
    public function __invoke(Request $request): ApiJsonResponse
    {
        $actor = $this->authenticator->actor($request);
        $query = QueryParameters::fromRequest($request);

        // Значения по умолчанию и границы offset и limit задаёт core, здесь они передаются как пришли.
        $posts = $this->getFeed->handle(new GetFeedQuery(
            $actor,
            $query->optionalString('offset', 'offset'),
            $query->optionalString('limit', 'limit'),
        ));

        return new ApiJsonResponse(200, array_map(PostPayload::fromPost(...), $posts));
    }
}
