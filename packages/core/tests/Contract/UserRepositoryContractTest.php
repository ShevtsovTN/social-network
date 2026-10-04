<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\Interests;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Контракт порта UserRepository. Каждая реализация (in-memory, PDO в native, PDO в symfony)
 * наследует этот класс и возвращает себя из createRepository().
 */
abstract class UserRepositoryContractTest extends TestCase
{
    abstract protected function createRepository(): UserRepository;

    public function testReturnsNullForUnknownId(): void
    {
        $repository = $this->createRepository();

        self::assertNull($repository->findById(UserId::fromString(UserFixture::DEFAULT_ID)));
    }

    public function testFindsSavedUserById(): void
    {
        $repository = $this->createRepository();
        $user = UserFixture::create();

        $repository->save($user);

        self::assertEquals($user, $repository->findById($user->id));
    }

    public function testRestoresEveryField(): void
    {
        $repository = $this->createRepository();
        $user = UserFixture::create();
        $repository->save($user);

        $found = $repository->findById($user->id);

        self::assertNotNull($found);
        self::assertSame($user->id->value, $found->id->value);
        self::assertSame($user->firstName->value, $found->firstName->value);
        self::assertSame($user->lastName->value, $found->lastName->value);
        self::assertSame($user->birthDate->toString(), $found->birthDate->toString());
        self::assertSame($user->gender, $found->gender);
        self::assertSame($user->city->value, $found->city->value);
        self::assertSame($user->passwordHash->value, $found->passwordHash->value);
    }

    public function testKeepsInterestsOrderAndSpecialCharacters(): void
    {
        $repository = $this->createRepository();
        $user = UserFixture::create();
        $repository->save($user);

        $found = $repository->findById($user->id);

        self::assertNotNull($found);
        self::assertSame(['hiking', 'chess', 'Кофе, чай; "кавычки"'], $found->interests->values);
    }

    public function testStoresUserWithoutInterests(): void
    {
        $repository = $this->createRepository();
        $user = UserFixture::create(interests: Interests::none());
        $repository->save($user);

        $found = $repository->findById($user->id);

        self::assertNotNull($found);
        self::assertTrue($found->interests->isEmpty());
    }

    public function testKeepsDifferentUsersSeparate(): void
    {
        $repository = $this->createRepository();
        $first = UserFixture::create(UserFixture::DEFAULT_ID);
        $second = UserFixture::create(UserFixture::OTHER_ID, Interests::fromList(['go']));
        $repository->save($first);
        $repository->save($second);

        self::assertSame(['hiking', 'chess', 'Кофе, чай; "кавычки"'], $repository->findById($first->id)?->interests->values);
        self::assertSame(['go'], $repository->findById($second->id)?->interests->values);
    }

    public function testTreatsSqlMetacharactersInUserDataAsPlainText(): void
    {
        $repository = $this->createRepository();
        $user = UserFixture::create(interests: Interests::fromList(["'; DROP TABLE users; --"]));
        $repository->save($user);

        $found = $repository->findById($user->id);

        self::assertNotNull($found);
        self::assertSame(["'; DROP TABLE users; --"], $found->interests->values);
    }
}
