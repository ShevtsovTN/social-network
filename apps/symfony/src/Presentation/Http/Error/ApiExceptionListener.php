<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Error;

use SocialNetwork\Core\Application\Login\InvalidCredentials;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserNotFound;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\MalformedRequest;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\ApiJsonResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Единственное место, где доменные/presentation-исключения превращаются в HTTP-ответ.
 * Заодно приводит собственные 404/405 Symfony к тому же JSON, что отдаёт apps/native.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        $event->setResponse(match (true) {
            $exception instanceof InvalidUserData,
            $exception instanceof MalformedRequest => new ApiJsonResponse(400, [
                'message' => $exception->getMessage(),
                'field' => $exception->field,
            ]),
            $exception instanceof InvalidCredentials => new ApiJsonResponse(401, [
                'message' => $exception->getMessage(),
            ]),
            $exception instanceof UserNotFound => new ApiJsonResponse(404, [
                'message' => $exception->getMessage(),
            ]),
            $exception instanceof NotFoundHttpException => new ApiJsonResponse(404, [
                'message' => sprintf('Route %s was not found.', $this->requestedPath($request)),
            ]),
            $exception instanceof MethodNotAllowedHttpException => new ApiJsonResponse(405, [
                'message' => sprintf('Method %s is not allowed for %s.', $request->getMethod(), $this->requestedPath($request)),
            ]),
            default => $this->internalError($exception),
        });
    }

    /**
     * Путь в сообщениях берётся из исходного REQUEST_URI так же, как в apps/native:
     * getPathInfo() срезал бы `/index.php` и склеивал бы повторные слэши.
     */
    private function requestedPath(Request $request): string
    {
        return (string) (parse_url($request->getRequestUri(), PHP_URL_PATH) ?? '/');
    }

    private function internalError(\Throwable $exception): ApiJsonResponse
    {
        error_log(sprintf('[symfony] unhandled %s: %s', $exception::class, $exception->getMessage()));

        return new ApiJsonResponse(500, ['message' => 'Internal Server Error']);
    }
}
