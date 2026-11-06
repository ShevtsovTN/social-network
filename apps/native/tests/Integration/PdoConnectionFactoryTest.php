<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Integration;

use PDOException;
use PHPUnit\Framework\TestCase;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoConnectionFactory;

/**
 * Зависший сервер БД не должен держать воркер php-fpm до таймаута TCP (минуты): после аварии primary
 * все воркеры застряли бы в запросах к недоступному адресу, и приложение перестало бы отвечать, в том числе на чтение.
 */
final class PdoConnectionFactoryTest extends TestCase
{
    public function testConnectingToUnreachableHostFailsQuickly(): void
    {
        // 192.0.2.1 (TEST-NET-1) зарезервирован для документации: пакеты туда уходят в никуда или отбрасываются сразу.
        $startedAt = microtime(true);

        try {
            PdoConnectionFactory::create('192.0.2.1', '5432', 'social', 'social', 'unused');
            self::fail('Подключение к недоступному адресу не должно удаваться');
        } catch (PDOException) {
            self::assertLessThan(5.0, microtime(true) - $startedAt, 'connect_timeout должен оборвать ожидание');
        }
    }

    public function testConnectsToConfiguredDatabase(): void
    {
        $connection = PdoConnectionFactory::create(
            (string) getenv('DB_HOST'),
            (string) getenv('DB_PORT'),
            (string) getenv('DB_NAME'),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD'),
        );

        self::assertSame('1', (string) $connection->query('SELECT 1')->fetchColumn());
    }
}
