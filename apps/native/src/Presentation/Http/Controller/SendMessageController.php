<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\Core\Application\SendMessage\SendMessage;
use SocialNetwork\Core\Application\SendMessage\SendMessageCommand;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class SendMessageController implements Controller
{
    public function __construct(private RequestAuthenticator $authenticator, private SendMessage $sendMessage)
    {
    }

    public function handle(Request $request, array $routeParameters): JsonResponse
    {
        $actor = $this->authenticator->actor($request);

        $this->sendMessage->handle(new SendMessageCommand(
            $actor,
            $routeParameters['userId'],
            $request->jsonBody()->string('text'),
        ));

        return JsonResponse::noContent();
    }
}
