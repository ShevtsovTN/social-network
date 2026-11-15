<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use Redis;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisConnection;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisKeyspace;

/**
 * Общая часть integration-тестов Redis-адаптеров: настоящий Redis из compose, у каждого теста свой префикс ключей,
 * после теста все ключи с этим префиксом удаляются. Чужие ключи (кэш приложения, другие тесты) не затрагиваются.
 */
trait UsesIsolatedRedis
{
    private ?RedisKeyspace $isolatedKeyspace = null;
    private ?RedisConnection $isolatedConnection = null;

    protected function keyspace(): RedisKeyspace
    {
        return $this->isolatedKeyspace ??= new RedisKeyspace('test:' . bin2hex(random_bytes(6)));
    }

    protected function connection(float $readTimeoutSeconds = 1.0): RedisConnection
    {
        return $this->isolatedConnection ??= new RedisConnection(
            (string) getenv('REDIS_HOST'),
            (int) getenv('REDIS_PORT'),
            (string) getenv('REDIS_PASSWORD'),
            false,
            1.0,
            $readTimeoutSeconds,
        );
    }

    protected function tearDown(): void
    {
        $this->cleanUpRedis();
    }

    /**
     * Удаляет ключи этого теста. Тест со своим tearDown вызывает метод сам.
     */
    protected function cleanUpRedis(): void
    {
        if ($this->isolatedKeyspace === null) {
            return;
        }

        $redis = $this->connection()->client();
        $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
        $iterator = null;

        while (($keys = $redis->scan($iterator, $this->isolatedKeyspace->pattern(), 100)) !== false) {
            if ($keys !== []) {
                $redis->del($keys);
            }
        }

        $this->isolatedKeyspace = null;
        $this->isolatedConnection = null;
    }
}
