<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\Interests;
use SocialNetwork\Core\Domain\User\NamePrefix;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Контракт порта UserRepository. Каждая реализация (in-memory, PDO в native, PDO в symfony)
 * наследует этот класс и возвращает себя из createRepository().
 */
abstract class UserRepositoryContractTest extends TestCase
{
    private const string ID_A = 'a0000000-0000-4000-8000-00000000000a';
    private const string ID_B = 'b0000000-0000-4000-8000-00000000000b';
    private const string ID_C = 'c0000000-0000-4000-8000-00000000000c';

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

    // Поиск по префиксам. Реализации на PDO проверяются на общей БД, где могут лежать сгенерированные анкеты
    // с реальными именами, поэтому здесь имена с префиксами «Zq» и «Ъы», которых в таких данных нет.

    public function testSearchRequiresBothPrefixesToMatch(): void
    {
        $repository = $this->createRepository();
        $match = UserFixture::create(self::ID_A, firstName: 'Zqivan', lastName: 'Zqpetrov');
        $repository->save($match);
        $repository->save(UserFixture::create(self::ID_B, firstName: 'Zqivan', lastName: 'Zqsidorov'));
        $repository->save(UserFixture::create(self::ID_C, firstName: 'Zqoleg', lastName: 'Zqpetrov'));

        self::assertEquals([$match], $this->search($repository, 'Zqiv', 'Zqpet'));
    }

    public function testSearchResultIsSortedById(): void
    {
        $repository = $this->createRepository();
        foreach ([self::ID_C, self::ID_A, self::ID_B] as $id) {
            $repository->save(UserFixture::create($id, firstName: 'Zqivan', lastName: 'Zqpetrov'));
        }

        self::assertSame([self::ID_A, self::ID_B, self::ID_C], self::ids($this->search($repository, 'Zq', 'Zq')));
    }

    public function testSearchIsCaseSensitive(): void
    {
        $repository = $this->createRepository();
        $repository->save(UserFixture::create(self::ID_A, firstName: 'Zqivan', lastName: 'Zqpetrov'));

        self::assertSame([], $this->search($repository, 'zqiv', 'Zqpet'));
        self::assertSame([], $this->search($repository, 'Zqiv', 'ZQPET'));
    }

    public function testSearchTreatsLikeWildcardsAsPlainCharacters(): void
    {
        $repository = $this->createRepository();
        $repository->save(UserFixture::create(self::ID_A, firstName: 'Zqivan', lastName: 'Zqpetrov'));
        $repository->save(UserFixture::create(self::ID_B, firstName: 'Zq_van', lastName: 'Zq%etrov'));
        $repository->save(UserFixture::create(self::ID_C, firstName: 'Zq\\van', lastName: 'Zq\\etrov'));

        self::assertSame([], $this->search($repository, '%', '%'));
        self::assertSame([self::ID_B], self::ids($this->search($repository, 'Zq_', 'Zq%')));
        self::assertSame([self::ID_C], self::ids($this->search($repository, 'Zq\\', 'Zq\\')));
    }

    public function testSearchAcceptsWholeNameAsPrefix(): void
    {
        $repository = $this->createRepository();
        $repository->save(UserFixture::create(self::ID_A, firstName: 'Zqivan', lastName: 'Zqpetrov'));

        self::assertSame([self::ID_A], self::ids($this->search($repository, 'Zqivan', 'Zqpetrov')));
        self::assertSame([], $this->search($repository, 'Zqivana', 'Zqpetrov'));
    }

    public function testSearchMatchesCyrillicPrefixes(): void
    {
        $repository = $this->createRepository();
        $repository->save(UserFixture::create(self::ID_A, firstName: 'Ъыслав', lastName: 'Ъыков'));

        self::assertSame([self::ID_A], self::ids($this->search($repository, 'Ъы', 'Ъык')));
        self::assertSame([], $this->search($repository, 'ъы', 'Ъык'));
    }

    public function testSearchReturnsEmptyListWhenNothingMatches(): void
    {
        $repository = $this->createRepository();
        $repository->save(UserFixture::create(self::ID_A, firstName: 'Zqivan', lastName: 'Zqpetrov'));

        self::assertSame([], $this->search($repository, 'Zqoleg', 'Zqpetrov'));
    }

    /**
     * @return list<User>
     */
    private function search(UserRepository $repository, string $firstNamePrefix, string $lastNamePrefix): array
    {
        return $repository->searchByNamePrefix(
            NamePrefix::fromString($firstNamePrefix, 'firstName'),
            NamePrefix::fromString($lastNamePrefix, 'lastName'),
        );
    }

    /**
     * @param list<User> $users
     * @return list<string>
     */
    private static function ids(array $users): array
    {
        return array_map(static fn (User $user): string => $user->id->value, $users);
    }
}
