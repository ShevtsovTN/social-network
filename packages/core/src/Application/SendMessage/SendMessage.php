<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\SendMessage;

use SocialNetwork\Core\Domain\Dialog\InvalidDialogData;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserNotFound;

interface SendMessage
{
    /**
     * Отправляет сообщение от текущего пользователя получателю; оно сразу видно в диалоге обоим.
     * Не идемпотентно: повторный вызов создаёт ещё одно сообщение.
     *
     * @throws InvalidUserData если recipientId не UUID (field = recipientId)
     * @throws InvalidDialogData если текст не прошёл валидацию (field = text)
     *                           или получатель совпадает с отправителем (field = recipientId)
     * @throws UserNotFound если пользователя recipientId нет
     */
    public function handle(SendMessageCommand $command): void;
}
