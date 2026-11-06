<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Persistence;

use PDO;

final class PdoConnectionFactory
{
    private const int CONNECT_TIMEOUT_SECONDS = 2;
    private const int TCP_USER_TIMEOUT_MILLISECONDS = 3000;

    private function __construct()
    {
    }

    public static function create(string $host, string $port, string $dbname, string $user, string $password): PDO
    {
        // Таймауты на случай молчащего сервера БД (разрыв сети, потеря пакетов): без них воркер php-fpm ждёт
        // минутами. tcp_user_timeout (мс) ограничивает ожидание подтверждения отправленных данных на установленном,
        // в том числе постоянном, соединении, а также установку нового. Время подключения ограничено ниже
        // через PDO::ATTR_TIMEOUT. На разрешение DNS эти таймауты не влияют (см. dns_opt в docker-compose.yaml).
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;tcp_user_timeout=%d',
            $host,
            $port,
            $dbname,
            self::TCP_USER_TIMEOUT_MILLISECONDS,
        );

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            // connect_timeout в DSN не работает: pdo_pgsql дописывает свой (30 с) позже и он перекрывает. Работает только эта опция.
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ]);
    }
}
