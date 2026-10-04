<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Controller;

use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

interface Controller
{
    /**
     * @param array<string, string> $routeParameters значения плейсхолдеров пути, например ['id' => '...']
     */
    public function handle(Request $request, array $routeParameters): JsonResponse;
}
