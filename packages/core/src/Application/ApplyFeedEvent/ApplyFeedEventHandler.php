<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\ApplyFeedEvent;

use SocialNetwork\Core\Application\Feed\Event\FeedEvent;
use SocialNetwork\Core\Application\Feed\Event\FriendAdded;
use SocialNetwork\Core\Application\Feed\Event\FriendRemoved;
use SocialNetwork\Core\Application\Feed\Event\PostDeleted;
use SocialNetwork\Core\Application\Feed\Event\PostEdited;
use SocialNetwork\Core\Application\Feed\Event\PostPublished;
use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\Core\Application\Port\FeedSource;
use SocialNetwork\Core\Application\Port\FriendshipRepository;
use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Feed\FeedLimits;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Работает воркер. Данные берёт с primary (PostRepository и FeedSource этого handler'а подключает composition root
 * к primary): реплика может отставать, и свежий пост или правка не должны теряться.
 */
final readonly class ApplyFeedEventHandler implements ApplyFeedEvent
{
    /** Сколько подписчиков обрабатывается за один обмен с кэшем. */
    private const int FOLLOWERS_BATCH_SIZE = 500;

    public function __construct(
        private PostRepository $posts,
        private FriendshipRepository $friendships,
        private FeedSource $feedSource,
        private FeedCache $feedCache,
        private PostCache $postCache,
    ) {
    }

    public function handle(FeedEvent $event): void
    {
        match (true) {
            $event instanceof PostPublished => $this->publishPost($event),
            $event instanceof PostEdited => $this->refreshPostBody($event->postId),
            $event instanceof PostDeleted => $this->removePost($event),
            $event instanceof FriendAdded => $this->backfill($event),
            $event instanceof FriendRemoved => $this->feedCache->forget($event->userId),
            default => throw new \LogicException(sprintf('Unknown feed event %s.', $event::class)),
        };
    }

    private function publishPost(PostPublished $event): void
    {
        $post = $this->posts->findById($event->postId);

        if ($post === null) {
            // Пост уже удалён: класть в ленты нечего, событие удаления уберёт остатки.
            return;
        }

        // Тело кладём в кэш сразу: читатели берут его из кэша, а не с реплики, которая может отставать.
        $this->postCache->putMany([$post]);

        $entry = new FeedEntry($post->id, $post->createdAt);

        $this->forEachFollowersBatch($event->authorId, function (array $followers) use ($entry): void {
            $this->feedCache->mergeIfPresent($followers, [$entry]);
        });
    }

    private function refreshPostBody(PostId $postId): void
    {
        $post = $this->posts->findById($postId);

        if ($post === null) {
            $this->postCache->forget($postId);

            return;
        }

        $this->postCache->putMany([$post]);
    }

    private function removePost(PostDeleted $event): void
    {
        $this->postCache->forget($event->postId);

        $this->forEachFollowersBatch($event->authorId, function (array $followers) use ($event): void {
            $this->feedCache->removePost($followers, $event->postId);
        });
    }

    private function backfill(FriendAdded $event): void
    {
        $entries = $this->feedSource->latestByAuthor($event->friendId, FeedLimits::CAPACITY);

        if ($entries === []) {
            return;
        }

        $this->feedCache->mergeIfPresent([$event->userId], $entries);
    }

    /**
     * @param callable(list<UserId>): void $action
     */
    private function forEachFollowersBatch(UserId $authorId, callable $action): void
    {
        $after = null;

        do {
            $followers = $this->friendships->followerIdsOf($authorId, self::FOLLOWERS_BATCH_SIZE, $after);

            if ($followers === []) {
                return;
            }

            $action($followers);
            $after = $followers[array_key_last($followers)];
        } while (count($followers) === self::FOLLOWERS_BATCH_SIZE);
    }
}
