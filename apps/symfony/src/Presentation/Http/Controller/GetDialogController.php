<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\GetDialog\GetDialog;
use SocialNetwork\Core\Application\GetDialog\GetDialogQuery;
use SocialNetwork\SymfonyApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\QueryParameters;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\MessagePayload;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class GetDialogController
{
    public function __construct(private RequestAuthenticator $authenticator, private GetDialog $getDialog)
    {
    }

    // Контракт (docs/openapi.json): пагинация limit/before и поле id в элементах, которых нет в спецификации OTUS.
    #[Route('/dialog/{userId}/list', methods: ['GET'])]
    public function __invoke(Request $request, string $userId): ApiJsonResponse
    {
        $actor = $this->authenticator->actor($request);
        $query = QueryParameters::fromRequest($request);

        // Значения по умолчанию и границы limit и before задаёт core, здесь они передаются как пришли.
        $messages = $this->getDialog->handle(new GetDialogQuery(
            $actor,
            $userId,
            $query->optionalString('limit', 'limit'),
            $query->optionalString('before', 'before'),
        ));

        return new ApiJsonResponse(200, array_map(MessagePayload::fromMessage(...), $messages));
    }
}
