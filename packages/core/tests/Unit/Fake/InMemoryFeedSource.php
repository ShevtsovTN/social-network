<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\FeedSource;
use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class InMemoryFeedSource implements FeedSource
{
    public function __construct(
        private InMemoryPostRepository $posts,
        private InMemoryFriendshipRepository $friendships,
    ) {
    }

    public function latestFromFriends(UserId $reader, int $limit): array
    {
        $friendIds = $this->friendships->friendIdsOf($reader);

        return $this->newest(
            static fn (Post $post): bool => in_array($post->authorId->value, $friendIds, true),
            $limit,
        );
    }

    public function latestByAuthor(UserId $author, int $limit): array
    {
        return $this->newest(static fn (Post $post): bool => $post->authorId->equals($author), $limit);
    }

    /**
     * @param callable(Post): bool $filter
     *
     * @return list<FeedEntry>
     */
    private function newest(callable $filter, int $limit): array
    {
        $entries = array_map(
            static fn (Post $post): FeedEntry => new FeedEntry($post->id, $post->createdAt),
            array_values(array_filter($this->posts->all(), $filter)),
        );

        usort($entries, FeedEntry::compareNewestFirst(...));

        return array_slice($entries, 0, $limit);
    }
}
