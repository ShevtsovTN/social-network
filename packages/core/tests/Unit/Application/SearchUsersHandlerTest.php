<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\SearchUsers\SearchUsersHandler;
use SocialNetwork\Core\Application\SearchUsers\SearchUsersQuery;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryUserRepository;
use SocialNetwork\Core\Tests\Unit\Support\AssertsInvalidUserData;
use SocialNetwork\CoreContract\UserFixture;

final class SearchUsersHandlerTest extends TestCase
{
    use AssertsInvalidUserData;

    private InMemoryUserRepository $users;
    private SearchUsersHandler $handler;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->handler = new SearchUsersHandler($this->users);
    }

    public function testReturnsUsersMatchingBothPrefixes(): void
    {
        $match = UserFixture::create(UserFixture::DEFAULT_ID, firstName: 'Константин', lastName: 'Осипов');
        $this->users->save($match);
        $this->users->save(UserFixture::create(UserFixture::OTHER_ID, firstName: 'Константин', lastName: 'Петров'));

        self::assertEquals([$match], $this->handler->handle(new SearchUsersQuery('Конст', 'Оси')));
    }

    public function testTrimsPrefixes(): void
    {
        $this->users->save(UserFixture::create(firstName: 'Константин', lastName: 'Осипов'));

        self::assertCount(1, $this->handler->handle(new SearchUsersQuery(' Конст ', 'Оси ')));
    }

    public function testReturnsEmptyListWhenNothingMatches(): void
    {
        $this->users->save(UserFixture::create(firstName: 'Константин', lastName: 'Осипов'));

        self::assertSame([], $this->handler->handle(new SearchUsersQuery('Олег', 'Оси')));
    }

    public function testRejectsInvalidPrefixesWithDomainFieldNames(): void
    {
        $this->assertInvalidField('firstName', fn () => $this->handler->handle(new SearchUsersQuery('', 'Оси')));
        $this->assertInvalidField('lastName', fn () => $this->handler->handle(new SearchUsersQuery('Конст', '   ')));
        $this->assertInvalidField('lastName', fn () => $this->handler->handle(new SearchUsersQuery('Конст', str_repeat('я', 101))));
    }
}
