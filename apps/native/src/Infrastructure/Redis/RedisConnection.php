<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Infrastructure\Redis;

use Closure;
use Redis;
use RedisException;

/**
 * Ленивое подключение к Redis: соединение открывается при первой команде, а не при сборке зависимостей.
 * Так недоступный Redis обнаруживается там, где его ждут (кэш превращает сбой в CacheUnavailable, и чтение
 * ленты уходит в БД), а не при создании контроллера. Неудачное подключение не запоминается: следующая команда пробует снова.
 *
 * phpredis бросает исключения только при ошибках соединения; ошибки сервера (NOSCRIPT, OOM, WRONGTYPE) возвращает
 * как false и кладёт текст в getLastError(). execute() превращает их в RedisException.
 */
final class RedisConnection
{
    private ?Redis $client = null;

    /**
     * @param bool $persistent постоянное соединение (php-fpm: как PDO::ATTR_PERSISTENT), в воркере обычное
     * @param float $readTimeoutSeconds таймаут чтения; у потребителя очереди он должен быть больше времени блокирующего ожидания
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        #[\SensitiveParameter]
        private readonly string $password,
        private readonly bool $persistent,
        private readonly float $connectTimeoutSeconds,
        private readonly float $readTimeoutSeconds,
    ) {
    }

    /**
     * @throws RedisException если подключиться или войти не удалось
     */
    public function client(): Redis
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $redis = new Redis();

        if ($this->persistent) {
            $redis->pconnect($this->host, $this->port, $this->connectTimeoutSeconds, 'social-network', 0, $this->readTimeoutSeconds);
        } else {
            $redis->connect($this->host, $this->port, $this->connectTimeoutSeconds, null, 0, $this->readTimeoutSeconds);
        }

        if ($this->password !== '' && $redis->auth($this->password) !== true) {
            throw new RedisException('Redis authentication failed.');
        }

        return $this->client = $redis;
    }

    /**
     * Выполняет команды и проверяет, что сервер не вернул ошибку.
     *
     * @template T
     *
     * @param Closure(Redis): T $operation
     *
     * @return T
     *
     * @throws RedisException
     */
    public function execute(Closure $operation): mixed
    {
        $redis = $this->client();
        $redis->clearLastError();

        $result = $operation($redis);

        $error = $redis->getLastError();

        if ($error !== null) {
            $redis->clearLastError();

            throw new RedisException($error);
        }

        return $result;
    }
}
