<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Routing;

use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Приводит маршрутизацию Symfony к строгости apps/native (контракт описывает ровно три маршрута):
 * без редиректа с лишним слэшем в конце пути (там 404) и без неявного HEAD как GET (там 405).
 * Приоритет 31: сразу после RouterListener (32), который кладёт результат матчинга в атрибуты запроса.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 31)]
final class StrictRoutingListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->attributes->get('_controller') === RedirectController::class . '::urlRedirectAction') {
            throw new NotFoundHttpException();
        }

        if ($request->getMethod() === 'HEAD') {
            throw new MethodNotAllowedHttpException(['GET']);
        }
    }
}
