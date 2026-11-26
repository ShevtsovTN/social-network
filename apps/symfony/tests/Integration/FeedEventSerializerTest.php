<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Feed\Event\FeedEvent;
use SocialNetwork\SymfonyApp\Infrastructure\Queue\FeedEventSerializer;
use SocialNetwork\CoreContract\FeedEventQueueContractTest;

final class FeedEventSerializerTest extends TestCase
{
    /**
     * @return iterable<string, array{FeedEvent}>
     */
    public static function events(): iterable
    {
        return FeedEventQueueContractTest::events();
    }

    #[DataProvider('events')]
    public function testEveryEventSurvivesARoundTrip(FeedEvent $event): void
    {
        $serializer = new FeedEventSerializer();

        $fields = $serializer->toFields($event);

        self::assertEquals($event, $serializer->fromFields($fields));
        foreach ($fields as $value) {
            self::assertIsString($value);
        }
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function unreadableFields(): iterable
    {
        yield 'empty' => [[]];
        yield 'no type' => [['postId' => 'b0000000-0000-4000-8000-000000000001']];
        yield 'unknown type' => [['type' => 'something_else']];
        yield 'type is not a string' => [['type' => ['post_edited']]];
        yield 'missing field' => [['type' => 'post_published', 'postId' => 'b0000000-0000-4000-8000-000000000001']];
        yield 'malformed uuid' => [['type' => 'post_edited', 'postId' => 'not-a-uuid']];
        yield 'field is not a string' => [['type' => 'friend_added', 'userId' => 5, 'friendId' => 6]];
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    #[DataProvider('unreadableFields')]
    public function testUnreadableFieldsGiveNull(array $fields): void
    {
        self::assertNull((new FeedEventSerializer())->fromFields($fields));
    }
}
