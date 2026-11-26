<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\SendMessage\SendMessage;
use SocialNetwork\Core\Application\SendMessage\SendMessageCommand;
use SocialNetwork\SymfonyApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\JsonBody;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\NoContentResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class SendMessageController
{
    public function __construct(private RequestAuthenticator $authenticator, private SendMessage $sendMessage)
    {
    }

    // Контракт (docs/openapi.json): путь с {userId}, а не {user_id}, ответ 204 без тела (в OTUS 200).
    #[Route('/dialog/{userId}/send', methods: ['POST'])]
    public function __invoke(Request $request, string $userId): NoContentResponse
    {
        $actor = $this->authenticator->actor($request);

        $this->sendMessage->handle(new SendMessageCommand($actor, $userId, JsonBody::fromRequest($request)->string('text')));

        return new NoContentResponse();
    }
}
