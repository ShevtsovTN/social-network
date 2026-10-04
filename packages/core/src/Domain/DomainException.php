<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain;

/**
 * Базовое исключение домена. Не знает про HTTP: маппинг в статусы делают приложения.
 */
abstract class DomainException extends \DomainException
{
}
