<?php

declare(strict_types=1);

/**
 * Composition root: единственное место, где создаются объекты и связываются зависимости.
 * Автовайринга нет, всё собирается руками. Возвращает готовые к использованию объекты,
 * ключ массива это имя класса.
 */

use SocialNetwork\Core\Application\GetUser\GetUserHandler;
use SocialNetwork\Core\Application\Login\LoginHandler;
use SocialNetwork\Core\Application\RegisterUser\RegisterUserHandler;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoConnectionFactory;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoUserRepository;
use SocialNetwork\NativeApp\Infrastructure\Security\Argon2PasswordHasher;
use SocialNetwork\NativeApp\Infrastructure\Security\HmacTokenService;
use SocialNetwork\NativeApp\Infrastructure\Security\RandomUserIdGenerator;
use SocialNetwork\NativeApp\Infrastructure\SystemClock;
use SocialNetwork\NativeApp\Presentation\Http\Application;
use SocialNetwork\NativeApp\Presentation\Http\Controller\GetUserController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\LoginController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\RegisterUserController;
use SocialNetwork\NativeApp\Presentation\Http\Error\ErrorHandler;
use SocialNetwork\NativeApp\Presentation\Http\Routing\Route;
use SocialNetwork\NativeApp\Presentation\Http\Routing\Router;

$requireEnv = static function (string $name): string {
    $value = getenv($name);

    if ($value === false || $value === '') {
        throw new RuntimeException(sprintf('Environment variable %s is not set.', $name));
    }

    return $value;
};

// Сервисы создаются лениво и один раз: неизвестный путь, 405 и ошибки окружения
// не затрагивают то, что маршруту не нужно (соединение с БД, секрет токена).
/**
 * @template T of object
 *
 * @param Closure(): T $factory
 *
 * @return Closure(): T
 */
$once = static function (Closure $factory): Closure {
    $instance = null;

    return static function () use (&$instance, $factory): object {
        return $instance ??= $factory();
    };
};

// Infrastructure
$connection = $once(static fn (): PDO => PdoConnectionFactory::create(
    $requireEnv('DB_HOST'),
    $requireEnv('DB_PORT'),
    $requireEnv('DB_NAME'),
    $requireEnv('DB_USER'),
    $requireEnv('DB_PASSWORD'),
));
$users = $once(static fn (): PdoUserRepository => new PdoUserRepository($connection()));
$passwordHasher = $once(static fn (): Argon2PasswordHasher => new Argon2PasswordHasher());
$tokenService = $once(static fn (): HmacTokenService => new HmacTokenService($requireEnv('AUTH_TOKEN_SECRET')));
$idGenerator = $once(static fn (): RandomUserIdGenerator => new RandomUserIdGenerator());
$clock = $once(static fn (): SystemClock => new SystemClock());

// Presentation: handler'ы (Application) собираются вместе с контроллером, когда маршрут подошёл.
$errorHandler = new ErrorHandler();
$router = new Router([
    new Route('POST', '/login', static fn (): LoginController => new LoginController(
        new LoginHandler($users(), $passwordHasher(), $tokenService()),
    )),
    new Route('POST', '/user/register', static fn (): RegisterUserController => new RegisterUserController(
        new RegisterUserHandler($users(), $passwordHasher(), $idGenerator(), $clock()),
    )),
    new Route('GET', '/user/get/{id}', static fn (): GetUserController => new GetUserController(
        new GetUserHandler($users()),
    )),
]);

return [
    Application::class => new Application($router, $errorHandler),
    ErrorHandler::class => $errorHandler,
];
