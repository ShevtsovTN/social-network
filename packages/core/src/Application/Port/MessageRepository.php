<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\Dialog\DialogId;
use SocialNetwork\Core\Domain\Dialog\DialogPageRequest;
use SocialNetwork\Core\Domain\Dialog\Message;

interface MessageRepository
{
    /**
     * Сохраняет новое сообщение. Только вставка: повторное сохранение сообщения с тем же id в том же диалоге
     * завершается исключением инфраструктуры. Сохранённое сообщение сразу видно в findPage().
     */
    public function add(Message $message): void;

    /**
     * Страница диалога: не больше $page->limit самых новых сообщений, а если задан $page->before — самых новых
     * среди тех, чей id меньше него. Сообщения с id, равным before, на странице нет; существовать оно не обязано.
     *
     * Порядок сообщений задаёт id (не время отправки): внутри страницы от меньшего id к большему,
     * то есть от старых сообщений к новым.
     *
     * @return list<Message>
     */
    public function findPage(DialogId $dialogId, DialogPageRequest $page): array;
}
