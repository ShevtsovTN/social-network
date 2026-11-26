<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Redis;

use Closure;
use DateTimeImmutable;
use Redis;
use RedisException;
use SocialNetwork\Core\Application\Port\CacheUnavailable;
use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\Post\PostText;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Тело поста в одной строке Redis (JSON) с TTL. Нечитаемое или повреждённое значение считается промахом:
 * кэш не источник истины, пост просто загрузится из БД заново.
 */
final readonly class RedisPostCache implements PostCache
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private RedisConnection $connection,
        private RedisKeyspace $keyspace,
        private int $ttlSeconds,
    ) {
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<string|false> $values */
        $values = $this->execute(fn (Redis $redis): mixed => $redis->mget(
            array_map($this->keyspace->post(...), $ids),
        ));

        $found = [];

        foreach ($values as $value) {
            $post = is_string($value) ? self::decode($value) : null;

            if ($post !== null) {
                $found[$post->id->value] = $post;
            }
        }

        return $found;
    }

    public function putMany(array $posts): void
    {
        if ($posts === []) {
            return;
        }

        $this->execute(function (Redis $redis) use ($posts): void {
            $pipeline = $redis->multi(Redis::PIPELINE);

            foreach ($posts as $post) {
                $pipeline->setex($this->keyspace->post($post->id), $this->ttlSeconds, self::encode($post));
            }

            $results = $pipeline->exec();

            if (!is_array($results) || in_array(false, $results, true)) {
                throw new RedisException('Storing posts in the cache failed.');
            }
        });
    }

    public function forget(PostId $id): void
    {
        $this->execute(fn (Redis $redis): mixed => $redis->del($this->keyspace->post($id)));
    }

    private static function encode(Post $post): string
    {
        return json_encode([
            'id' => $post->id->value,
            'authorId' => $post->authorId->value,
            'text' => $post->text->value,
            'createdAt' => $post->createdAt->format(self::TIMESTAMP_FORMAT),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function decode(string $json): ?Post
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['authorId'] ?? null)
                || !is_string($data['text'] ?? null) || !is_string($data['createdAt'] ?? null)) {
                return null;
            }

            return Post::reconstitute(
                PostId::fromString($data['id']),
                UserId::fromString($data['authorId']),
                PostText::fromString($data['text']),
                new DateTimeImmutable($data['createdAt']),
            );
        } catch (\Exception) {
            // JsonException, исключения домена и разбор даты: все они значат «значение повреждено».
            return null;
        }
    }

    /**
     * @param Closure(Redis): mixed $operation
     *
     * @throws CacheUnavailable
     */
    private function execute(Closure $operation): mixed
    {
        try {
            return $this->connection->execute($operation);
        } catch (RedisException $exception) {
            throw CacheUnavailable::because($exception);
        }
    }
}
