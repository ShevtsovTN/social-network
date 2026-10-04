<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Routing;

use RuntimeException;

final class RouteNotFound extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('Route %s was not found.', $path));
    }
}
