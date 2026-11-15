<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\ProcessNextFeedEvent;

interface ProcessNextFeedEvent
{
    /**
     * Один шаг воркера: берёт следующее событие из очереди, применяет его и подтверждает.
     * Цикл и обработку сигналов остановки делает приложение.
     *
     * @return bool true, если событие было обработано; false, если очередь пуста
     *
     * Если применение события бросило исключение, событие не подтверждается (придёт снова), исключение
     * пробрасывается.
     */
    public function handle(ProcessNextFeedEventCommand $command): bool;
}
