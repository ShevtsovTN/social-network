<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetFeed;

use SocialNetwork\Core\Application\Port\CacheUnavailable;
use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\Core\Application\Port\FeedSource;
use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Feed\FeedLimits;
use SocialNetwork\Core\Domain\Feed\FeedPageRequest;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class GetFeedHandler implements GetFeed
{
    public function __construct(
        private FeedCache $feedCache,
        private FeedSource $feedSource,
        private PostCache $postCache,
        private PostRepository $posts,
    ) {
    }

    public function handle(GetFeedQuery $query): array
    {
        $page = FeedPageRequest::fromInput($query->offset, $query->limit);

        try {
            return $this->readThroughCache($query->readerId, $page);
        } catch (CacheUnavailable) {
            // Кэш не источник истины: без него лента строится прямо из БД, чуть медленнее, но верно.
            return $this->readFromDatabase($query->readerId, $page);
        }
    }

    /**
     * @return list<Post>
     */
    private function readThroughCache(UserId $reader, FeedPageRequest $page): array
    {
        $ids = $this->feedCache->read($reader, $page->offset, $page->limit);

        if ($ids === null) {
            // Промах: собираем ленту из БД (читает реплика, устаревание допустимо) и кладём в кэш целиком.
            $entries = $this->feedSource->latestFromFriends($reader, FeedLimits::CAPACITY);
            $this->feedCache->materialize($reader, $entries);
            $ids = $this->idsOfPage($entries, $page);
        }

        $found = $this->postCache->findMany($ids);
        $missing = array_values(array_filter($ids, static fn (PostId $id): bool => !isset($found[$id->value])));

        if ($missing !== []) {
            $loaded = $this->posts->findByIds($missing);
            $this->postCache->putMany($loaded);

            foreach ($loaded as $post) {
                $found[$post->id->value] = $post;
            }
        }

        return $this->inOrder($ids, $found);
    }

    /**
     * @return list<Post>
     */
    private function readFromDatabase(UserId $reader, FeedPageRequest $page): array
    {
        $entries = $this->feedSource->latestFromFriends($reader, FeedLimits::CAPACITY);
        $ids = $this->idsOfPage($entries, $page);

        $found = [];
        foreach ($this->posts->findByIds($ids) as $post) {
            $found[$post->id->value] = $post;
        }

        return $this->inOrder($ids, $found);
    }

    /**
     * @param list<FeedEntry> $entries
     *
     * @return list<PostId>
     */
    private function idsOfPage(array $entries, FeedPageRequest $page): array
    {
        return array_map(
            static fn (FeedEntry $entry): PostId => $entry->postId,
            array_slice($entries, $page->offset, $page->limit),
        );
    }

    /**
     * Порядок страницы задают id из ленты. Пост без тела (его удалили, а событие ещё не обработано,
     * или реплика отстаёт) пропускается.
     *
     * @param list<PostId> $ids
     * @param array<string, Post> $posts
     *
     * @return list<Post>
     */
    private function inOrder(array $ids, array $posts): array
    {
        $ordered = [];

        foreach ($ids as $id) {
            if (isset($posts[$id->value])) {
                $ordered[] = $posts[$id->value];
            }
        }

        return $ordered;
    }
}
