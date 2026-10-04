<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Routing;

use RuntimeException;

final class MethodNotAllowed extends RuntimeException
{
    public static function forPath(string $method, string $path): self
    {
        return new self(sprintf('Method %s is not allowed for %s.', $method, $path));
    }
}
