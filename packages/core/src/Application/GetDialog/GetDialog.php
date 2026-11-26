<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetDialog;

use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;
use SocialNetwork\Core\Domain\Dialog\Message;
use SocialNetwork\Core\Domain\User\InvalidUserData;

interface GetDialog
{
    /**
     * Страница диалога текущего пользователя с собеседником: сообщения обоих направлений от старых к новым
     * (подробнее о странице — DialogPageRequest). Нет сообщений, в том числе если собеседника не существует, —
     * пустой список.
     *
     * @return list<Message>
     *
     * @throws InvalidUserData если companionId не UUID (field = companionId)
     * @throws InvalidDialogData если собеседник совпадает с текущим пользователем (field = companionId)
     *                           или limit, before неверны (field = limit или before)
     */
    public function handle(GetDialogQuery $query): array;
}
