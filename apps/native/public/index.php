<?php

declare(strict_types=1);

use SocialNetwork\NativeApp\Presentation\Http\Application;
use SocialNetwork\NativeApp\Presentation\Http\Error\ErrorHandler;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;

require __DIR__ . '/../vendor/autoload.php';

try {
    $container = require __DIR__ . '/../config/container.php';
    $container[Application::class]->handle(Request::fromGlobals())->send();
} catch (Throwable $exception) {
    // Упала загрузка контейнера или сама Application: ошибки сборки зависимостей маршрута ловит Application.
    (new ErrorHandler())->toResponse($exception)->send();
}
