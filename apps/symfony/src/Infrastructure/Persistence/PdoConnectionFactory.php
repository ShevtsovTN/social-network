<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Persistence;

use PDO;

final class PdoConnectionFactory
{
    private function __construct()
    {
    }

    public static function create(string $host, string $port, string $dbname, string $user, string $password): PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbname);

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
        ]);
    }
}
