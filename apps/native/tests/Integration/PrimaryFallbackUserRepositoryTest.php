<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Integration;

use PDO;
use PDOException;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\NamePrefix;
use SocialNetwork\CoreContract\UserFixture;
use SocialNetwork\CoreContract\UserRepositoryContractTest;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoConnectionFactory;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoUserRepository;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PrimaryFallbackUserRepository;

/**
 * Реплика в этих тестах это второе соединение с той же БД. Primary работает в открытой транзакции,
 * и её незакоммиченные строки второму соединению не видны: это точная модель реплики, которая ещё не получила запись.
 * Общий контракт порта проверяется на том же декораторе: он обязан вести себя как любой UserRepository.
 */
final class PrimaryFallbackUserRepositoryTest extends UserRepositoryContractTest
{
    private PDO $primaryConnection;
    private PDO $replicaConnection;

    protected function setUp(): void
    {
        $this->primaryConnection = self::connect();
        $this->replicaConnection = self::connectSeparately();
        $this->primaryConnection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->primaryConnection->inTransaction()) {
            $this->primaryConnection->rollBack();
        }

        // Строки, записанные «в реплику» напрямую, закоммичены: убираем, чтобы не мешать другим тестам.
        $this->replicaConnection->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => UserFixture::DEFAULT_ID]);
    }

    /**
     * Для общего контракта реплика не отстаёт: и она, и primary это одно соединение.
     */
    protected function createRepository(): UserRepository
    {
        return new PrimaryFallbackUserRepository(
            new PdoUserRepository($this->primaryConnection),
            fn (): UserRepository => new PdoUserRepository($this->primaryConnection),
        );
    }

    /**
     * Реплика, которая не видит незакоммиченные записи primary.
     */
    private function createLaggingRepository(): UserRepository
    {
        return new PrimaryFallbackUserRepository(
            new PdoUserRepository($this->replicaConnection),
            fn (): UserRepository => new PdoUserRepository($this->primaryConnection),
        );
    }

    public function testFindsUserMissingOnReplicaThroughPrimary(): void
    {
        $user = UserFixture::create();
        $repository = $this->createLaggingRepository();
        $repository->save($user);

        self::assertNull((new PdoUserRepository($this->replicaConnection))->findById($user->id), 'реплика ещё не видит запись');
        self::assertEquals($user, $repository->findById($user->id));
    }

    public function testDoesNotAskPrimaryWhenReplicaHasUser(): void
    {
        $user = UserFixture::create();
        (new PdoUserRepository($this->replicaConnection))->save($user);

        $repository = new PrimaryFallbackUserRepository(
            new PdoUserRepository($this->replicaConnection),
            static fn (): UserRepository => throw new \LogicException('primary не должен запрашиваться'),
        );

        self::assertEquals($user, $repository->findById($user->id));
    }

    public function testSearchReadsReplicaOnly(): void
    {
        $repository = $this->createLaggingRepository();
        // Уникальные имя и фамилия: в общей БД могут быть анкеты «Иван Petrov» от других прогонов.
        $repository->save(UserFixture::create(firstName: 'Лагимя', lastName: 'Lagsurname'));

        self::assertSame([], $repository->searchByNamePrefix(NamePrefix::fromString('Лагимя', 'firstName'), NamePrefix::fromString('Lagsurname', 'lastName')));
    }

    public function testReportsNotFoundWhenPrimaryIsUnavailable(): void
    {
        $repository = new PrimaryFallbackUserRepository(
            new PdoUserRepository($this->replicaConnection),
            static fn (): UserRepository => throw new PDOException('primary недоступен'),
        );

        self::assertNull($repository->findById(UserFixture::create()->id));
    }

    /**
     * PdoConnectionFactory открывает постоянные соединения, и второй вызов с теми же параметрами вернул бы
     * то же самое соединение. Для «реплики» нужно отдельное, поэтому PDO создаётся напрямую и не постоянный.
     */
    private static function connectSeparately(): PDO
    {
        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME')),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    private static function connect(): PDO
    {
        return PdoConnectionFactory::create(
            (string) getenv('DB_HOST'),
            (string) getenv('DB_PORT'),
            (string) getenv('DB_NAME'),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD'),
        );
    }
}
