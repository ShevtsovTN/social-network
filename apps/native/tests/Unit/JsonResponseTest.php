<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final class JsonResponseTest extends TestCase
{
    public function testNoContentHasNoBody(): void
    {
        $response = JsonResponse::noContent();

        self::assertSame(204, $response->status);
        self::assertNull($response->body);
    }

    public function testEmptyListIsStillABody(): void
    {
        $response = new JsonResponse(200, []);

        self::assertSame([], $response->body);
    }
}
