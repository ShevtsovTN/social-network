<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;

final class RequestBearerTokenTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headers(): iterable
    {
        yield 'bearer' => ['Bearer v1.abc.def', 'v1.abc.def'];
        yield 'scheme is case insensitive' => ['bearer v1.abc.def', 'v1.abc.def'];
        yield 'upper case scheme' => ['BEARER token', 'token'];
        yield 'no header' => ['', ''];
        yield 'another scheme' => ['Basic dXNlcjpwYXNz', ''];
        yield 'scheme without token' => ['Bearer', ''];
        yield 'scheme with empty token' => ['Bearer ', ''];
        yield 'token with spaces' => ['Bearer a b', ''];
        yield 'two spaces after the scheme' => ['Bearer  token', ''];
        yield 'trailing line break' => ["Bearer token\n", ''];
        yield 'token only' => ['v1.abc.def', ''];
    }

    #[DataProvider('headers')]
    public function testExtractsTheTokenOrAnEmptyString(string $header, string $expected): void
    {
        $request = Request::fromParts('GET', '/post/feed', '', [], $header);

        self::assertSame($expected, $request->bearerToken());
    }

    public function testRequestWithoutAuthorizationHasNoToken(): void
    {
        self::assertSame('', Request::fromParts('GET', '/post/feed', '')->bearerToken());
    }
}
