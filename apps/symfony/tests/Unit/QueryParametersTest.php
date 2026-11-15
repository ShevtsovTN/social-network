<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\MalformedRequest;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\QueryParameters;
use Symfony\Component\HttpFoundation\Request;

final class QueryParametersTest extends TestCase
{
    public function testAbsentOptionalParameterIsNull(): void
    {
        $query = QueryParameters::fromRequest(Request::create('/post/feed'));

        self::assertNull($query->optionalString('offset', 'offset'));
    }

    public function testPresentOptionalParameterIsReturnedAsIs(): void
    {
        $query = QueryParameters::fromRequest(Request::create('/post/feed?offset=25&limit=&other=1'));

        self::assertSame('25', $query->optionalString('offset', 'offset'));
        self::assertSame('', $query->optionalString('limit', 'limit'));
    }

    public function testNonStringOptionalParameterIsMalformed(): void
    {
        $query = QueryParameters::fromRequest(Request::create('/post/feed?offset[]=1&offset[]=2'));

        try {
            $query->optionalString('offset', 'offset');
            self::fail('MalformedRequest expected.');
        } catch (MalformedRequest $exception) {
            self::assertSame('offset', $exception->field);
        }
    }
}
