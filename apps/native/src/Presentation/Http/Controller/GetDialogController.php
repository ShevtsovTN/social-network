<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetDialog\GetDialog;
use SocialNetwork\Core\Application\GetDialog\GetDialogQuery;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;
use SocialNetwork\NativeApp\Presentation\Http\Response\MessagePayload;

final readonly class GetDialogController implements Controller
{
    public function __construct(private RequestAuthenticator $authenticator, private GetDialog $getDialog)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $actor = $this->authenticator->actor($request);
        $query = $request->query();

        // Значения по умолчанию и границы limit и before задаёт core, здесь они передаются как пришли.
        $messages = $this->getDialog->handle(new GetDialogQuery(
            $actor,
            $routeParameters['userId'],
            $query->optionalString('limit', 'limit'),
            $query->optionalString('before', 'before'),
        ));

        return new JsonResponse(200, array_map(MessagePayload::fromMessage(...), $messages));
    }
}
