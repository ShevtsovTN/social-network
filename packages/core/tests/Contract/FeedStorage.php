<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use SocialNetwork\Core\Application\Port\FeedSource;
use SocialNetwork\Core\Application\Port\FriendshipRepository;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Application\Port\UserRepository;

/**
 * Одно хранилище (одна БД или одно in-memory состояние), через которое contract-тесты видят все репозитории ленты.
 * Репозитории обязаны работать над общими данными: друзья ссылаются на пользователей, посты на авторов,
 * FeedSource читает и то и другое. Каждый тест получает чистое хранилище (PDO-реализации откатывают транзакцию).
 */
interface FeedStorage
{
    public function users(): UserRepository;

    public function posts(): PostRepository;

    public function friendships(): FriendshipRepository;

    public function feedSource(): FeedSource;
}
