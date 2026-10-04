<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Routing;

use Closure;
use SocialNetwork\NativeApp\Presentation\Http\Controller\Controller;

/**
 * Маршрут: метод и путь с плейсхолдерами вида /user/get/{id}.
 * Контроллер создаётся фабрикой и только когда маршрут подошёл: запрос на неизвестный путь
 * не собирает зависимости и не открывает соединение с БД (как в apps/symfony).
 */
final readonly class Route
{
    private string $pattern;

    /**
     * @param Closure(): Controller $controllerFactory
     */
    public function __construct(
        public string $method,
        string $path,
        private Closure $controllerFactory,
    ) {
        $this->pattern = '#^' . preg_replace('#\\\\\{(\w+)\\\\\}#', '(?P<$1>[^/]+)', preg_quote($path, '#')) . '$#';
    }

    public function controller(): Controller
    {
        return ($this->controllerFactory)();
    }

    /**
     * @return array<string, string>|null параметры пути, если путь подходит; иначе null
     */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
