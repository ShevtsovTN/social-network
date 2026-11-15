<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Redis;

use Redis;

/**
 * Lua-скрипт, который вызывается по SHA1 (EVALSHA); если сервер его ещё не знает (после рестарта, FLUSHALL),
 * скрипт отправляется целиком (EVAL) и дальше кэшируется сервером.
 */
final readonly class LuaScript
{
    private string $sha;

    public function __construct(private string $source)
    {
        $this->sha = sha1($source);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $arguments
     */
    public function run(Redis $redis, array $keys, array $arguments): mixed
    {
        $payload = [...$keys, ...$arguments];
        $result = $redis->evalSha($this->sha, $payload, count($keys));

        if ($result === false && str_starts_with((string) $redis->getLastError(), 'NOSCRIPT')) {
            $redis->clearLastError();
            $result = $redis->eval($this->source, $payload, count($keys));
        }

        return $result;
    }
}
