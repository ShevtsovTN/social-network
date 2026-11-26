<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialNetwork\SymfonyApp\Presentation\Http\Response\NoContentResponse;
use Symfony\Component\HttpFoundation\Request;

final class NoContentResponseTest extends TestCase
{
    public function testHasStatus204AndNoBody(): void
    {
        $response = new NoContentResponse();
        $response->prepare(Request::create('/friend/set/x', 'PUT'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertFalse($response->headers->has('Content-Type'));
    }
}
