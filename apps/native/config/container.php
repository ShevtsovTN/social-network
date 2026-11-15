<?php

declare(strict_types=1);

/**
 * Composition root: единственное место, где создаются объекты и связываются зависимости.
 * Автовайринга нет, всё собирается руками. Возвращает готовые к использованию объекты,
 * ключ массива это имя класса (кроме воркера ленты, там фабрика).
 */

use SocialNetwork\Core\Application\AddFriend\AddFriendHandler;
use SocialNetwork\Core\Application\ApplyFeedEvent\ApplyFeedEventHandler;
use SocialNetwork\Core\Application\Authenticate\AuthenticateHandler;
use SocialNetwork\Core\Application\CreatePost\CreatePostHandler;
use SocialNetwork\Core\Application\DeletePost\DeletePostHandler;
use SocialNetwork\Core\Application\GetFeed\GetFeedHandler;
use SocialNetwork\Core\Application\GetPost\GetPostHandler;
use SocialNetwork\Core\Application\GetUser\GetUserHandler;
use SocialNetwork\Core\Application\Login\LoginHandler;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventHandler;
use SocialNetwork\Core\Application\RegisterUser\RegisterUserHandler;
use SocialNetwork\Core\Application\RemoveFriend\RemoveFriendHandler;
use SocialNetwork\Core\Application\SearchUsers\SearchUsersHandler;
use SocialNetwork\Core\Application\UpdatePost\UpdatePostHandler;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoConnectionFactory;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoFeedSource;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoFriendshipRepository;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoPostRepository;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoUserRepository;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PrimaryFallbackUserRepository;
use SocialNetwork\NativeApp\Infrastructure\Queue\FeedEventSerializer;
use SocialNetwork\NativeApp\Infrastructure\Queue\RedisFeedEventConsumer;
use SocialNetwork\NativeApp\Infrastructure\Queue\RedisFeedEventPublisher;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisConnection;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisFeedCache;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisKeyspace;
use SocialNetwork\NativeApp\Infrastructure\Redis\RedisPostCache;
use SocialNetwork\NativeApp\Infrastructure\Security\Argon2PasswordHasher;
use SocialNetwork\NativeApp\Infrastructure\Security\HmacTokenService;
use SocialNetwork\NativeApp\Infrastructure\Security\RandomPostIdGenerator;
use SocialNetwork\NativeApp\Infrastructure\Security\RandomUserIdGenerator;
use SocialNetwork\NativeApp\Infrastructure\SystemClock;
use SocialNetwork\NativeApp\Presentation\Http\Application;
use SocialNetwork\NativeApp\Presentation\Http\Auth\RequestAuthenticator;
use SocialNetwork\NativeApp\Presentation\Http\Controller\CreatePostController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\DeleteFriendController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\DeletePostController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\GetFeedController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\GetPostController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\GetUserController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\LoginController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\RegisterUserController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\SearchUsersController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\SetFriendController;
use SocialNetwork\NativeApp\Presentation\Http\Controller\UpdatePostController;
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
// Два подключения к БД: primary (запись) и реплики через haproxy (чтение). Один и тот же PdoUserRepository
// создаётся дважды, и use case получает тот, что подходит его семантике: register и login работают с primary
// (логин идёт сразу после регистрации и не должен упереться в лаг реплики), get и search читают с реплик
// (get при промахе перечитывает primary, search только реплику).
$primaryConnection = $once(static fn (): PDO => PdoConnectionFactory::create(
    $requireEnv('DB_HOST'),
    $requireEnv('DB_PORT'),
    $requireEnv('DB_NAME'),
    $requireEnv('DB_USER'),
    $requireEnv('DB_PASSWORD'),
));
$replicaConnection = $once(static fn (): PDO => PdoConnectionFactory::create(
    $requireEnv('DB_READ_HOST'),
    $requireEnv('DB_READ_PORT'),
    $requireEnv('DB_NAME'),
    $requireEnv('DB_USER'),
    $requireEnv('DB_PASSWORD'),
));
$primaryUsers = $once(static fn (): PdoUserRepository => new PdoUserRepository($primaryConnection()));
$replicaUsers = $once(static fn (): PdoUserRepository => new PdoUserRepository($replicaConnection()));
// get читает с реплики, а при промахе спрашивает primary: свежесозданная анкета может ещё не дойти до реплики.
$replicaUsersWithPrimaryFallback = $once(static fn (): PrimaryFallbackUserRepository => new PrimaryFallbackUserRepository(
    $replicaUsers(),
    $primaryUsers,
));
$passwordHasher = $once(static fn (): Argon2PasswordHasher => new Argon2PasswordHasher());
$tokenService = $once(static fn (): HmacTokenService => new HmacTokenService($requireEnv('AUTH_TOKEN_SECRET')));
$idGenerator = $once(static fn (): RandomUserIdGenerator => new RandomUserIdGenerator());
$clock = $once(static fn (): SystemClock => new SystemClock());

// Лента постов (ДЗ «Лента постов»). Роутинг чтения и записи, как и выше, решается здесь, а не в репозиториях:
// - создание, правка и удаление постов, друзья, GET /post/get и backfill воркера работают с primary
//   (свежесозданный пост должен быть виден сразу, без лага реплики);
// - холодная пересборка ленты и тела постов для страницы ленты читают реплику (устаревание допустимо:
//   воркер кладёт тело нового поста в кэш сразу, поэтому реплика нужна только при промахе кэша).
$primaryPosts = $once(static fn (): PdoPostRepository => new PdoPostRepository($primaryConnection()));
$replicaPosts = $once(static fn (): PdoPostRepository => new PdoPostRepository($replicaConnection()));
$primaryFriendships = $once(static fn (): PdoFriendshipRepository => new PdoFriendshipRepository($primaryConnection()));
$primaryFeedSource = $once(static fn (): PdoFeedSource => new PdoFeedSource($primaryConnection()));
$replicaFeedSource = $once(static fn (): PdoFeedSource => new PdoFeedSource($replicaConnection()));
$postIdGenerator = $once(static fn (): RandomPostIdGenerator => new RandomPostIdGenerator());

// Redis: кэш ленты и очередь событий. Ключи, стрим и группа с префиксом приложения: native и symfony не делят данные.
// Подключение постоянное (php-fpm), открывается при первой команде, а не при сборке контроллера.
$feedTtlSeconds = 3600;
$postTtlSeconds = 3600;
$redisConnection = $once(static fn (): RedisConnection => new RedisConnection(
    $requireEnv('REDIS_HOST'),
    (int) $requireEnv('REDIS_PORT'),
    $requireEnv('REDIS_PASSWORD'),
    true,
    1.0,
    1.0,
));
$redisKeyspace = $once(static fn (): RedisKeyspace => new RedisKeyspace($requireEnv('REDIS_KEY_PREFIX')));
$feedCache = $once(static fn (): RedisFeedCache => new RedisFeedCache($redisConnection(), $redisKeyspace(), $feedTtlSeconds));
$postCache = $once(static fn (): RedisPostCache => new RedisPostCache($redisConnection(), $redisKeyspace(), $postTtlSeconds));
$feedEventPublisher = $once(static fn (): RedisFeedEventPublisher => new RedisFeedEventPublisher(
    $redisConnection(),
    $redisKeyspace(),
    new FeedEventSerializer(),
));

// Воркер fan-out (bin/feed-worker.php): тот же код приложения отдельным процессом. Данные берёт с primary:
// реплика может отставать, а свежий пост или правка не должны теряться. Подключение к Redis обычное, не постоянное,
// с таймаутом чтения больше блокирующего ожидания XREADGROUP (5 с), иначе phpredis оборвёт соединение.
// Зависшее событие (воркер упал на середине) другой экземпляр забирает через 30 с.
$workerRedisConnection = $once(static fn (): RedisConnection => new RedisConnection(
    $requireEnv('REDIS_HOST'),
    (int) $requireEnv('REDIS_PORT'),
    $requireEnv('REDIS_PASSWORD'),
    false,
    1.0,
    10.0,
));
$feedWorker = $once(static fn (): ProcessNextFeedEventHandler => new ProcessNextFeedEventHandler(
    new RedisFeedEventConsumer(
        $workerRedisConnection(),
        $redisKeyspace(),
        new FeedEventSerializer(),
        (string) gethostname(),
        30_000,
    ),
    new ApplyFeedEventHandler(
        $primaryPosts(),
        $primaryFriendships(),
        $primaryFeedSource(),
        new RedisFeedCache($workerRedisConnection(), $redisKeyspace(), $feedTtlSeconds),
        new RedisPostCache($workerRedisConnection(), $redisKeyspace(), $postTtlSeconds),
    ),
));
$requestAuthenticator = $once(static fn (): RequestAuthenticator => new RequestAuthenticator(
    new AuthenticateHandler($tokenService()),
));

// Presentation: handler'ы (Application) собираются вместе с контроллером, когда маршрут подошёл.
$errorHandler = new ErrorHandler();
$router = new Router([
    new Route('POST', '/login', static fn (): LoginController => new LoginController(
        new LoginHandler($primaryUsers(), $passwordHasher(), $tokenService()),
    )),
    new Route('POST', '/user/register', static fn (): RegisterUserController => new RegisterUserController(
        new RegisterUserHandler($primaryUsers(), $passwordHasher(), $idGenerator(), $clock()),
    )),
    new Route('GET', '/user/get/{id}', static fn (): GetUserController => new GetUserController(
        new GetUserHandler($replicaUsersWithPrimaryFallback()),
    )),
    new Route('GET', '/user/search', static fn (): SearchUsersController => new SearchUsersController(
        new SearchUsersHandler($replicaUsers()),
    )),
    // Друзья, посты и лента. Отступления от стандартных REST-практик заданы контрактом (docs/openapi.json):
    // изменения и удаление идут через PUT, путь называется /friend/set, а не /friend/add, ответы без результата — 204.
    new Route('PUT', '/friend/set/{userId}', static fn (): SetFriendController => new SetFriendController(
        $requestAuthenticator(),
        new AddFriendHandler($primaryUsers(), $primaryFriendships(), $feedEventPublisher()),
    )),
    new Route('PUT', '/friend/delete/{userId}', static fn (): DeleteFriendController => new DeleteFriendController(
        $requestAuthenticator(),
        new RemoveFriendHandler($primaryFriendships(), $feedEventPublisher()),
    )),
    new Route('POST', '/post/create', static fn (): CreatePostController => new CreatePostController(
        $requestAuthenticator(),
        new CreatePostHandler($primaryPosts(), $postIdGenerator(), $clock(), $feedEventPublisher()),
    )),
    new Route('PUT', '/post/update', static fn (): UpdatePostController => new UpdatePostController(
        $requestAuthenticator(),
        new UpdatePostHandler($primaryPosts(), $feedEventPublisher()),
    )),
    new Route('PUT', '/post/delete/{id}', static fn (): DeletePostController => new DeletePostController(
        $requestAuthenticator(),
        new DeletePostHandler($primaryPosts(), $feedEventPublisher()),
    )),
    new Route('GET', '/post/get/{id}', static fn (): GetPostController => new GetPostController(
        new GetPostHandler($primaryPosts()),
    )),
    new Route('GET', '/post/feed', static fn (): GetFeedController => new GetFeedController(
        $requestAuthenticator(),
        new GetFeedHandler($feedCache(), $replicaFeedSource(), $postCache(), $replicaPosts()),
    )),
]);

return [
    Application::class => new Application($router, $errorHandler),
    ErrorHandler::class => $errorHandler,
    // Единственное исключение из «готовых объектов»: фабрика. Воркеру нужны все зависимости сразу,
    // а HTTP-запросы не должны их создавать.
    ProcessNextFeedEventHandler::class => $feedWorker,
];
