<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\BearerToken;
use Symfony\Component\HttpFoundation\Request;

final class BearerTokenTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headers(): iterable
    {
        yield 'bearer' => ['Bearer v1.abc.def', 'v1.abc.def'];
        yield 'scheme is case insensitive' => ['bearer v1.abc.def', 'v1.abc.def'];
        yield 'upper case scheme' => ['BEARER token', 'token'];
        yield 'another scheme' => ['Basic dXNlcjpwYXNz', ''];
        yield 'scheme without token' => ['Bearer', ''];
        yield 'token with spaces' => ['Bearer a b', ''];
        yield 'two spaces after the scheme' => ['Bearer  token', ''];
        yield 'token only' => ['v1.abc.def', ''];
    }

    #[DataProvider('headers')]
    public function testExtractsTheTokenOrAnEmptyString(string $header, string $expected): void
    {
        $request = Request::create('/post/feed', 'GET', server: ['HTTP_AUTHORIZATION' => $header]);

        self::assertSame($expected, BearerToken::fromRequest($request));
    }

    public function testRequestWithoutAuthorizationHasNoToken(): void
    {
        self::assertSame('', BearerToken::fromRequest(Request::create('/post/feed')));
    }
}
