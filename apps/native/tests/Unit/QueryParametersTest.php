<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialNetwork\NativeApp\Presentation\Http\Request\MalformedRequest;
use SocialNetwork\NativeApp\Presentation\Http\Request\QueryParameters;

final class QueryParametersTest extends TestCase
{
    public function testAbsentOptionalParameterIsNull(): void
    {
        self::assertNull((new QueryParameters([]))->optionalString('offset', 'offset'));
    }

    public function testPresentOptionalParameterIsReturnedAsIs(): void
    {
        $query = new QueryParameters(['offset' => '25', 'limit' => '', 'other' => '1']);

        self::assertSame('25', $query->optionalString('offset', 'offset'));
        self::assertSame('', $query->optionalString('limit', 'limit'));
    }

    public function testNonStringOptionalParameterIsMalformed(): void
    {
        $query = new QueryParameters(['offset' => ['1', '2']]);

        try {
            $query->optionalString('offset', 'offset');
            self::fail('MalformedRequest expected.');
        } catch (MalformedRequest $exception) {
            self::assertSame('offset', $exception->field);
        }
    }
}
