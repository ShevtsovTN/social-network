<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\FeedSource;
use SocialNetwork\Core\Application\Port\FriendshipRepository;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\CoreContract\FeedStorage;

/**
 * Общее in-memory состояние для contract-тестов репозиториев ленты и для unit-тестов use case'ов.
 */
final readonly class InMemoryFeedStorage implements FeedStorage
{
    public InMemoryUserRepository $userRepository;
    public InMemoryPostRepository $postRepository;
    public InMemoryFriendshipRepository $friendshipRepository;
    private InMemoryFeedSource $source;

    public function __construct()
    {
        $this->userRepository = new InMemoryUserRepository();
        $this->postRepository = new InMemoryPostRepository();
        $this->friendshipRepository = new InMemoryFriendshipRepository();
        $this->source = new InMemoryFeedSource($this->postRepository, $this->friendshipRepository);
    }

    public function users(): UserRepository
    {
        return $this->userRepository;
    }

    public function posts(): PostRepository
    {
        return $this->postRepository;
    }

    public function friendships(): FriendshipRepository
    {
        return $this->friendshipRepository;
    }

    public function feedSource(): FeedSource
    {
        return $this->source;
    }
}
