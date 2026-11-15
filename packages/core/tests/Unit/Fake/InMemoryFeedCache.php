<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Feed\FeedLimits;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

final class InMemoryFeedCache implements FeedCache
{
    /** @var array<string, array<string, FeedEntry>> readerId => [postId => entry], новые первыми */
    private array $feeds = [];

    public function read(UserId $reader, int $offset, int $limit): ?array
    {
        if (!isset($this->feeds[$reader->value])) {
            return null;
        }

        return array_values(array_map(
            static fn (FeedEntry $entry): PostId => $entry->postId,
            array_slice($this->feeds[$reader->value], $offset, $limit),
        ));
    }

    public function materialize(UserId $reader, array $entries): void
    {
        $this->feeds[$reader->value] ??= [];
        $this->merge($reader, $entries);
    }

    public function mergeIfPresent(array $readers, array $entries): void
    {
        foreach ($readers as $reader) {
            if (isset($this->feeds[$reader->value])) {
                $this->merge($reader, $entries);
            }
        }
    }

    public function removePost(array $readers, PostId $postId): void
    {
        foreach ($readers as $reader) {
            unset($this->feeds[$reader->value][$postId->value]);
        }
    }

    public function forget(UserId $reader): void
    {
        unset($this->feeds[$reader->value]);
    }

    public function has(UserId $reader): bool
    {
        return isset($this->feeds[$reader->value]);
    }

    /**
     * @param list<FeedEntry> $entries
     */
    private function merge(UserId $reader, array $entries): void
    {
        $feed = $this->feeds[$reader->value];

        foreach ($entries as $entry) {
            $feed[$entry->postId->value] = $entry;
        }

        uasort($feed, FeedEntry::compareNewestFirst(...));

        $this->feeds[$reader->value] = array_slice($feed, 0, FeedLimits::CAPACITY, true);
    }
}
