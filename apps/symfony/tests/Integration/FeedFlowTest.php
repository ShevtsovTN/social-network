<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\AddFriend\AddFriendCommand;
use SocialNetwork\Core\Application\AddFriend\AddFriendHandler;
use SocialNetwork\Core\Application\ApplyFeedEvent\ApplyFeedEventHandler;
use SocialNetwork\Core\Application\CreatePost\CreatePostCommand;
use SocialNetwork\Core\Application\CreatePost\CreatePostHandler;
use SocialNetwork\Core\Application\DeletePost\DeletePostCommand;
use SocialNetwork\Core\Application\DeletePost\DeletePostHandler;
use SocialNetwork\Core\Application\Feed\Event\PostPublished;
use SocialNetwork\Core\Application\GetFeed\GetFeedHandler;
use SocialNetwork\Core\Application\GetFeed\GetFeedQuery;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventCommand;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventHandler;
use SocialNetwork\Core\Application\RemoveFriend\RemoveFriendCommand;
use SocialNetwork\Core\Application\RemoveFriend\RemoveFriendHandler;
use SocialNetwork\Core\Application\UpdatePost\UpdatePostCommand;
use SocialNetwork\Core\Application\UpdatePost\UpdatePostHandler;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\CoreContract\FeedFixture;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisFeedCache;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisPostCache;
use SocialNetwork\SymfonyApp\Infrastructure\Security\RandomPostIdGenerator;
use SocialNetwork\SymfonyApp\Infrastructure\SystemClock;

/**
 * Весь поток ленты на настоящих PostgreSQL и Redis, без HTTP: use case'ы core публикуют события, воркер
 * (ProcessNextFeedEvent) обновляет кэши, GetFeed читает. Показывает итоговую согласованность: пока воркер
 * не отработал, готовая лента не меняется. PostgreSQL здесь одна транзакция с откатом (она же «реплика»).
 */
final class FeedFlowTest extends TestCase
{
    use UsesIsolatedRedis;

    private const int READER = 1;
    private const int AUTHOR = 2;

    private PdoFeedStorage $storage;
    private RedisFeedEventChannel $channel;
    private RedisFeedCache $feedCache;
    private RedisPostCache $postCache;
    private GetFeedHandler $getFeed;
    private ProcessNextFeedEventHandler $worker;

    protected function setUp(): void
    {
        $this->storage = PdoFeedStorage::begin();
        FeedFixture::addUsers($this->storage, self::READER, self::AUTHOR);

        $this->channel = new RedisFeedEventChannel($this->connection(), $this->keyspace());
        $this->feedCache = new RedisFeedCache($this->connection(), $this->keyspace(), 3600);
        $this->postCache = new RedisPostCache($this->connection(), $this->keyspace(), 3600);
        $this->getFeed = new GetFeedHandler($this->feedCache, $this->storage->feedSource(), $this->postCache, $this->storage->posts());
        $this->worker = new ProcessNextFeedEventHandler(
            $this->channel->consumer(),
            new ApplyFeedEventHandler(
                $this->storage->posts(),
                $this->storage->friendships(),
                $this->storage->feedSource(),
                $this->feedCache,
                $this->postCache,
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->storage->rollBack();
        $this->cleanUpRedis();
    }

    public function testFirstReadBuildsTheFeedFromTheDatabaseWithoutTheWorker(): void
    {
        $first = $this->post(self::AUTHOR, 'Первый пост');
        $second = $this->post(self::AUTHOR, 'Второй пост');
        $this->befriend(self::READER, self::AUTHOR);

        self::assertSame([$second->value, $first->value], $this->feedIds(self::READER));
    }

    public function testNewPostReachesAReadyFeedOnlyAfterTheWorkerRuns(): void
    {
        $this->befriend(self::READER, self::AUTHOR);
        $this->processEvents();
        self::assertSame([], $this->feedIds(self::READER), 'The feed is now materialized (and empty).');

        $post = $this->post(self::AUTHOR, 'Свежий пост');

        self::assertSame([], $this->feedIds(self::READER), 'The feed is eventually consistent: the worker has not run yet.');

        $this->processEvents();

        self::assertSame([$post->value], $this->feedIds(self::READER));
    }

    public function testWorkerCachesTheBodyOfANewPost(): void
    {
        $post = $this->post(self::AUTHOR, 'Тело поста');

        $this->processEvents();

        $cached = $this->postCache->findMany([$post]);
        self::assertSame('Тело поста', $cached[$post->value]->text->value);
    }

    public function testWorkerDoesNotCreateFeedsForReadersWhoNeverAskedForOne(): void
    {
        $this->befriend(self::READER, self::AUTHOR);
        $this->processEvents();

        $this->post(self::AUTHOR, 'Пост');
        $this->processEvents();

        self::assertNull($this->feedCache->read(FeedFixture::userId(self::READER), 0, 10));
    }

    public function testAddingAFriendBackfillsTheirPostsIntoAnExistingFeed(): void
    {
        $first = $this->post(self::AUTHOR, 'Старый пост');
        $second = $this->post(self::AUTHOR, 'Новее');
        $this->processEvents();
        self::assertSame([], $this->feedIds(self::READER), 'The reader has no friends yet; the feed is materialized and empty.');

        $this->befriend(self::READER, self::AUTHOR);
        self::assertSame([], $this->feedIds(self::READER), 'The backfill has not run yet.');

        $this->processEvents();

        self::assertSame([$second->value, $first->value], $this->feedIds(self::READER));
    }

    public function testRemovingAFriendResetsTheFeedSoItIsRebuiltWithoutThem(): void
    {
        $post = $this->post(self::AUTHOR, 'Пост друга');
        $this->befriend(self::READER, self::AUTHOR);
        self::assertSame([$post->value], $this->feedIds(self::READER));

        (new RemoveFriendHandler($this->storage->friendships(), $this->channel->publisher()))
            ->handle(new RemoveFriendCommand(FeedFixture::userId(self::READER), FeedFixture::userId(self::AUTHOR)->value));
        self::assertSame([$post->value], $this->feedIds(self::READER), 'The reset has not run yet.');

        $this->processEvents();

        self::assertSame([], $this->feedIds(self::READER));
    }

    public function testDeletedPostLeavesTheFeedAfterTheWorkerRuns(): void
    {
        $kept = $this->post(self::AUTHOR, 'Остаётся');
        $deleted = $this->post(self::AUTHOR, 'Удаляется');
        $this->befriend(self::READER, self::AUTHOR);
        $this->processEvents();
        self::assertSame([$deleted->value, $kept->value], $this->feedIds(self::READER));

        (new DeletePostHandler($this->storage->posts(), $this->channel->publisher()))
            ->handle(new DeletePostCommand(FeedFixture::userId(self::AUTHOR), $deleted->value));
        $this->processEvents();

        self::assertSame([$kept->value], $this->feedIds(self::READER));
        self::assertSame([], $this->postCache->findMany([$deleted]));
    }

    public function testEditedTextShowsUpInTheFeedAfterTheWorkerRuns(): void
    {
        $post = $this->post(self::AUTHOR, 'Старый текст');
        $this->befriend(self::READER, self::AUTHOR);
        $this->processEvents();
        self::assertSame('Старый текст', $this->feedTexts(self::READER)[0]);

        (new UpdatePostHandler($this->storage->posts(), $this->channel->publisher()))
            ->handle(new UpdatePostCommand(FeedFixture::userId(self::AUTHOR), $post->value, 'Новый текст'));

        self::assertSame('Старый текст', $this->feedTexts(self::READER)[0], 'The cached body is refreshed by the worker.');

        $this->processEvents();

        self::assertSame('Новый текст', $this->feedTexts(self::READER)[0]);
    }

    public function testAnEventProcessedTwiceChangesNothing(): void
    {
        $post = $this->post(self::AUTHOR, 'Пост');
        $this->befriend(self::READER, self::AUTHOR);
        $this->processEvents();
        $this->feedIds(self::READER);

        // Повторная доставка того же события: публикуем ещё раз.
        $this->channel->publisher()->publish(new PostPublished($post, FeedFixture::userId(self::AUTHOR)));
        $this->processEvents();

        self::assertSame([$post->value], $this->feedIds(self::READER));
    }

    private function post(int $authorNumber, string $text): PostId
    {
        usleep(1000);

        return (new CreatePostHandler(
            $this->storage->posts(),
            new RandomPostIdGenerator(),
            new SystemClock(),
            $this->channel->publisher(),
        ))->handle(new CreatePostCommand(FeedFixture::userId($authorNumber), $text));
    }

    private function befriend(int $userNumber, int $friendNumber): void
    {
        (new AddFriendHandler($this->storage->users(), $this->storage->friendships(), $this->channel->publisher()))
            ->handle(new AddFriendCommand(FeedFixture::userId($userNumber), FeedFixture::userId($friendNumber)->value));
    }

    /**
     * Обрабатывает все накопленные события, как воркер.
     */
    private function processEvents(): void
    {
        while ($this->worker->handle(new ProcessNextFeedEventCommand(0))) {
            // событие обработано, берём следующее
        }
    }

    /**
     * @return list<Post>
     */
    private function feed(int $readerNumber): array
    {
        return $this->getFeed->handle(new GetFeedQuery(FeedFixture::userId($readerNumber), '0', '100'));
    }

    /**
     * @return list<string>
     */
    private function feedIds(int $readerNumber): array
    {
        return array_map(static fn (Post $post): string => $post->id->value, $this->feed($readerNumber));
    }

    /**
     * @return list<string>
     */
    private function feedTexts(int $readerNumber): array
    {
        return array_map(static fn (Post $post): string => $post->text->value, $this->feed($readerNumber));
    }
}
