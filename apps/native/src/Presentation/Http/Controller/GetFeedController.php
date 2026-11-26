<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetFeed\GetFeed;
use SocialNetwork\Core\Application\GetFeed\GetFeedQuery;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;
use SocialNetwork\NativeApp\Presentation\Http\Response\PostPayload;

final readonly class GetFeedController implements Controller
{
    public function __construct(private RequestAuthenticator $authenticator, private GetFeed $getFeed)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $actor = $this->authenticator->actor($request);
        $query = $request->query();

        // Значения по умолчанию и границы offset и limit задаёт core, здесь они передаются как пришли.
        $posts = $this->getFeed->handle(new GetFeedQuery(
            $actor,
            $query->optionalString('offset', 'offset'),
            $query->optionalString('limit', 'limit'),
        ));

        return new JsonResponse(200, array_map(PostPayload::fromPost(...), $posts));
    }
}
