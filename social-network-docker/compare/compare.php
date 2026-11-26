<?php

declare(strict_types=1);

// Сверка двух приложений: одинаковые запросы в оба, сравнение статуса, Content-Type, Allow и тела.
// Кейсы друзей, постов, ленты и диалогов идут с авторизацией и подготовленным состоянием (см. «Подготовка состояния»);
// для них должны работать воркеры ленты обоих приложений (make up).
// Запуск: php compare.php <urlNative> <urlSymfony>
// Значения, различающиеся по природе (UUID пользователей, подпись токена), заменяются на плейсхолдеры.
// Код выхода: 0 — новых расхождений нет, 1 — есть новые расхождения или устарела запись в списке известных, 2 — ошибка запуска.

const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

if ($argc !== 3) {
    fwrite(STDERR, "usage: compare.php <urlNative> <urlSymfony>\n");
    exit(2);
}

$apps = ['native' => rtrim($argv[1], '/'), 'symfony' => rtrim($argv[2], '/')];

/** @return array{status:int, contentType:string, allow:string, body:string} */
function send(string $baseUrl, string $method, string $path, ?string $body, ?string $contentType, ?string $authorization = null): array
{
    $responseHeaders = [];
    $handle = curl_init($baseUrl . $path);
    $requestHeaders = ['Expect:'];
    if ($authorization !== null) {
        $requestHeaders[] = 'Authorization: ' . $authorization;
    }
    if ($body !== null) {
        $requestHeaders[] = $contentType === null ? 'Content-Type:' : 'Content-Type: ' . $contentType;
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        },
    ]);
    $responseBody = curl_exec($handle);
    if ($responseBody === false) {
        $error = curl_error($handle);
        curl_close($handle);

        return ['status' => 0, 'contentType' => '', 'allow' => '', 'body' => 'curl error: ' . $error];
    }
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    $allow = array_map('trim', explode(',', $responseHeaders['allow'] ?? ''));
    sort($allow);

    return [
        'status' => $status,
        'contentType' => strtolower($responseHeaders['content-type'] ?? ''),
        'allow' => trim(implode(',', $allow), ','),
        'body' => (string) $responseBody,
    ];
}

function normalize(string $text, array $knownIds): string
{
    foreach ($knownIds as $placeholder => $id) {
        $text = str_ireplace($id, $placeholder, $text);
    }
    $text = preg_replace('/(v1\.<[A-Za-z0-9]+>\.)[0-9a-f]{64}/', '$1<hmac>', $text);

    return preg_replace('/' . UUID . '/', '<uuid>', $text);
}

function canonical(string $body): string
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $body;
    }
    $sort = static function (array $value) use (&$sort): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $sort($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };

    return json_encode($sort($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function payload(array $overrides = [], array $remove = []): string
{
    $data = array_merge([
        'firstName' => 'Ivan',
        'lastName' => 'Petrov',
        'birthDate' => '1990-05-17',
        'gender' => 'male',
        'interests' => ['hiking', 'chess'],
        'city' => 'Moscow',
        'password' => 's3cret-pass!',
    ], $overrides);
    foreach ($remove as $field) {
        unset($data[$field]);
    }

    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

// Пользователь, который регистрируется в каждом приложении первым: на него ссылаются кейсы {userId}.
$users = [];
foreach ($apps as $name => $baseUrl) {
    $registered = send($baseUrl, 'POST', '/user/register', payload(), 'application/json');
    $id = json_decode($registered['body'], true)['id'] ?? null;
    if ($registered['status'] !== 201 || !is_string($id)) {
        fwrite(STDERR, sprintf("%s: не удалось зарегистрировать пользователя (%d): %s\n", $name, $registered['status'], $registered['body']));
        exit(2);
    }
    $users[$name] = $id;
}

/**
 * Известные расхождения: имя кейса => причина. Приняты осознанно и не ломают код выхода.
 * Если кейс из списка стал совпадать, сверка завершается с ошибкой: запись нужно удалить.
 */
const KNOWN_DIFFERENCES = [
    'routes: percent-encoded /lo%67in' => 'Symfony декодирует путь и находит /login, native сопоставляет путь как есть (решение: оставить)',
];

const JSON_TYPE = 'application/json';
$longString = static fn (int $length): string => str_repeat('a', $length);
$password = 's3cret-pass!';

// [имя, метод, путь, тело, Content-Type, дополнительно: получить созданного пользователя]
$cases = [];
$add = static function (string $name, string $method, string $path, ?string $body = null, ?string $type = JSON_TYPE, bool|string $fetchCreated = false) use (&$cases): void {
    $cases[] = compact('name', 'method', 'path', 'body', 'type', 'fetchCreated') + ['auth' => null, 'fresh' => false, 'repeat' => 1, 'followUp' => null];
};

// Кейсы друзей, постов и ленты (ДЗ «Лента постов»). Дополнительно к $add:
// - $auth: значение заголовка Authorization; {tokenA} и {tokenB} подставляются токенами пользователей A и B конкретного приложения;
// - options: 'type' (Content-Type), 'fetchCreated' => 'post' (после 201 получить созданный пост), 'fresh' => true (перед запросом
//   пользователь A создаёт свежий пост, он доступен как {fresh}), 'repeat' => N (отправить запрос N раз подряд),
//   'followUp' => путь (после запроса получить его без авторизации, например проверить результат).
$addApi = static function (string $name, string $method, string $path, ?string $body = null, ?string $auth = null, array $options = []) use (&$cases): void {
    $cases[] = [
        'name' => $name,
        'method' => $method,
        'path' => $path,
        'body' => $body,
        'type' => array_key_exists('type', $options) ? $options['type'] : JSON_TYPE,
        'fetchCreated' => $options['fetchCreated'] ?? false,
        'auth' => $auth,
        'fresh' => $options['fresh'] ?? false,
        'repeat' => $options['repeat'] ?? 1,
        'followUp' => $options['followUp'] ?? null,
    ];
};

// Шаг между кейсами: действие в каждом приложении без сравнения ответов (например, дождаться состояния ленты).
$step = static function (string $name, callable $action) use (&$cases): void {
    $cases[] = ['name' => $name, 'step' => $action];
};

// --- регистрация ---
$add('register: success', 'POST', '/user/register', payload(), JSON_TYPE, true);
$add('register: interests dedupe and case', 'POST', '/user/register', payload(['interests' => ['Chess', 'chess', 'Hiking']]), JSON_TYPE, true);
$add('register: trimming of names', 'POST', '/user/register', payload(['firstName' => '  Ivan ', 'city' => ' Moscow ']), JSON_TYPE, true);
$add('register: unicode names', 'POST', '/user/register', payload(['firstName' => 'Иван', 'city' => 'Москва']), JSON_TYPE, true);
$add('register: extra unknown field', 'POST', '/user/register', payload(['unknown' => 'x']), JSON_TYPE, true);
$add('register: birthDate 1900-01-01', 'POST', '/user/register', payload(['birthDate' => '1900-01-01']));
$add('register: birthDate today', 'POST', '/user/register', payload(['birthDate' => date('Y-m-d')]));
$add('register: birthDate 1899-12-31', 'POST', '/user/register', payload(['birthDate' => '1899-12-31']));
$add('register: birthDate in the future', 'POST', '/user/register', payload(['birthDate' => '2999-01-01']));
$add('register: birthDate impossible date', 'POST', '/user/register', payload(['birthDate' => '2020-02-30']));
$add('register: birthDate wrong format', 'POST', '/user/register', payload(['birthDate' => '1990-5-1']));
$add('register: birthDate malformed', 'POST', '/user/register', payload(['birthDate' => 'not-a-date']));
$add('register: birthDate number', 'POST', '/user/register', payload(['birthDate' => 19900517]));
$add('register: gender uppercase', 'POST', '/user/register', payload(['gender' => 'MALE']));
$add('register: gender invalid', 'POST', '/user/register', payload(['gender' => 'robot']));
$add('register: gender other', 'POST', '/user/register', payload(['gender' => 'other']));
$add('register: empty firstName', 'POST', '/user/register', payload(['firstName' => '']));
$add('register: blank lastName', 'POST', '/user/register', payload(['lastName' => '   ']));
$add('register: firstName 100 chars', 'POST', '/user/register', payload(['firstName' => $longString(100)]));
$add('register: firstName 101 chars', 'POST', '/user/register', payload(['firstName' => $longString(101)]));
$add('register: firstName number', 'POST', '/user/register', payload(['firstName' => 123]));
$add('register: firstName array', 'POST', '/user/register', payload(['firstName' => ['x']]));
$add('register: firstName null', 'POST', '/user/register', payload(['firstName' => null]));
$add('register: empty city', 'POST', '/user/register', payload(['city' => '']));
$add('register: city 101 chars', 'POST', '/user/register', payload(['city' => $longString(101)]));
$add('register: interests empty list', 'POST', '/user/register', payload(['interests' => []]), JSON_TYPE, true);
$add('register: interests 20', 'POST', '/user/register', payload(['interests' => array_map(static fn (int $i): string => 'i' . $i, range(1, 20))]));
$add('register: interests 21', 'POST', '/user/register', payload(['interests' => array_map(static fn (int $i): string => 'i' . $i, range(1, 21))]));
$add('register: interests empty item', 'POST', '/user/register', payload(['interests' => ['chess', '']]));
$add('register: interests item 51 chars', 'POST', '/user/register', payload(['interests' => [$longString(51)]]));
$add('register: interests string', 'POST', '/user/register', payload(['interests' => 'chess']));
$add('register: interests non-string item', 'POST', '/user/register', payload(['interests' => [1, 2]]));
$add('register: password 7 chars', 'POST', '/user/register', payload(['password' => '1234567']));
$add('register: password 8 chars', 'POST', '/user/register', payload(['password' => '12345678']));
$add('register: password 128 chars', 'POST', '/user/register', payload(['password' => $longString(128)]));
$add('register: password 129 chars', 'POST', '/user/register', payload(['password' => $longString(129)]));
$add('register: password number', 'POST', '/user/register', payload(['password' => 12345678]));
foreach (['firstName', 'lastName', 'birthDate', 'gender', 'interests', 'city', 'password'] as $field) {
    $add("register: missing $field", 'POST', '/user/register', payload([], [$field]));
}
$add('register: body is not JSON', 'POST', '/user/register', 'not json');
$add('register: body truncated JSON', 'POST', '/user/register', '{"firstName":');
$add('register: empty body', 'POST', '/user/register', '');
$add('register: JSON array body', 'POST', '/user/register', '[]');
$add('register: JSON string body', 'POST', '/user/register', '"x"');
$add('register: JSON null body', 'POST', '/user/register', 'null');
$add('register: JSON number body', 'POST', '/user/register', '42');
$add('register: empty object body', 'POST', '/user/register', '{}');
$add('register: text/plain content type', 'POST', '/user/register', payload(), 'text/plain');
$add('register: no content type', 'POST', '/user/register', payload(), null);
$add('register: form content type', 'POST', '/user/register', payload(), 'application/x-www-form-urlencoded');
$add('register: no body at all', 'POST', '/user/register');

// --- логин ---
$login = static fn (array $data): string => json_encode($data);
$add('login: success', 'POST', '/login', '{"userId":"{userId}","password":"' . $password . '"}');
$add('login: uppercase userId', 'POST', '/login', '{"userId":"{userIdUpper}","password":"' . $password . '"}');
$add('login: wrong password', 'POST', '/login', '{"userId":"{userId}","password":"wrong-password"}');
$add('login: unknown user', 'POST', '/login', '{"userId":"99999999-9999-4999-8999-999999999999","password":"' . $password . '"}');
$add('login: malformed userId', 'POST', '/login', '{"userId":"not-a-uuid","password":"' . $password . '"}');
$add('login: userId number', 'POST', '/login', '{"userId":123,"password":"' . $password . '"}');
$add('login: userId null', 'POST', '/login', '{"userId":null,"password":"' . $password . '"}');
$add('login: empty password', 'POST', '/login', '{"userId":"{userId}","password":""}');
$add('login: password 129 chars', 'POST', '/login', '{"userId":"{userId}","password":"' . $longString(129) . '"}');
$add('login: password number', 'POST', '/login', '{"userId":"{userId}","password":12345678}');
$add('login: missing password', 'POST', '/login', '{"userId":"{userId}"}');
$add('login: missing userId', 'POST', '/login', '{"password":"' . $password . '"}');
$add('login: empty object', 'POST', '/login', '{}');
$add('login: body is not JSON', 'POST', '/login', 'not json');
$add('login: empty body', 'POST', '/login', '');
$add('login: JSON array body', 'POST', '/login', '[]');
$add('login: text/plain content type', 'POST', '/login', '{"userId":"{userId}","password":"' . $password . '"}', 'text/plain');
$add('login: no content type', 'POST', '/login', '{"userId":"{userId}","password":"' . $password . '"}', null);
$add('login: no body at all', 'POST', '/login');

// --- анкета ---
$add('get: success', 'GET', '/user/get/{userId}');
$add('get: uppercase id', 'GET', '/user/get/{userIdUpper}');
$add('get: not found', 'GET', '/user/get/99999999-9999-4999-8999-999999999999');
$add('get: invalid id', 'GET', '/user/get/not-a-uuid');
$add('get: numeric id', 'GET', '/user/get/123');
$add('get: id with unicode', 'GET', '/user/get/%D0%B8%D0%B4');
$add('get: id without dashes', 'GET', '/user/get/99999999999949998999999999999999');
$add('get: trailing slash', 'GET', '/user/get/{userId}/');
$add('get: extra segment', 'GET', '/user/get/{userId}/extra');
$add('get: no id', 'GET', '/user/get/');
$add('get: no id and no slash', 'GET', '/user/get');
$add('get: query string ignored', 'GET', '/user/get/{userId}?x=1');
$add('get: HEAD', 'HEAD', '/user/get/{userId}');
$add('get: POST not allowed', 'POST', '/user/get/{userId}', '{}');
$add('get: PUT not allowed', 'PUT', '/user/get/{userId}', '{}');
$add('get: DELETE not allowed', 'DELETE', '/user/get/{userId}');
$add('get: OPTIONS', 'OPTIONS', '/user/get/{userId}');

// --- поиск ---
// Префиксы из сгенерированных анкет (make seed), а не Ivan/Petrov: пользователи, которых регистрирует
// сама сверка, маскируются по-разному для native и symfony и дали бы ложное расхождение.
$search = static fn (string $query): string => '/user/search?' . $query;
$searchBy = static fn (string $firstName, string $lastName): string => $search(http_build_query(['first_name' => $firstName, 'last_name' => $lastName]));
$add('search: success', 'GET', $searchBy('Конст', 'Оси'));
$add('search: whole names', 'GET', $searchBy('Константин', 'Осипов'));
$add('search: many results', 'GET', $searchBy('Ив', 'Ив'));
$add('search: no matches', 'GET', $searchBy('Конст', 'Zzz'));
$add('search: trimming', 'GET', $searchBy(' Конст ', ' Оси '));
$add('search: case sensitive', 'GET', $searchBy('конст', 'оси'));
$add('search: percent is literal', 'GET', $searchBy('%', '%'));
$add('search: underscore is literal', 'GET', $searchBy('К_нст', 'О_и'));
$add('search: backslash is literal', 'GET', $searchBy('\\', '\\'));
$add('search: SQL metacharacters', 'GET', $searchBy("' OR 1=1 --", "'; DROP TABLE users; --"));
$add('search: first_name 100 chars', 'GET', $searchBy($longString(100), 'Оси'));
$add('search: first_name 101 chars', 'GET', $searchBy($longString(101), 'Оси'));
$add('search: empty first_name', 'GET', $searchBy('', 'Оси'));
$add('search: blank last_name', 'GET', $searchBy('Конст', '   '));
$add('search: control character', 'GET', $search('first_name=%D0%9A%0A%D0%BE&last_name=%D0%9E'));
$add('search: invalid UTF-8', 'GET', $search('first_name=%C3%28&last_name=%D0%9E'));
$add('search: missing first_name', 'GET', $search('last_name=%D0%9E'));
$add('search: missing last_name', 'GET', $search('first_name=%D0%9A'));
$add('search: no parameters', 'GET', '/user/search');
$add('search: array parameter', 'GET', $search('first_name[]=a&last_name=b'));
$add('search: duplicate parameter', 'GET', $search('first_name=Zzz&first_name=%D0%9A%D0%BE%D0%BD%D1%81%D1%82&last_name=%D0%9E%D1%81%D0%B8'));
$add('search: camelCase parameters', 'GET', $search('firstName=a&lastName=b'));
$add('search: trailing slash', 'GET', '/user/search/?first_name=a&last_name=b');
$add('search: HEAD', 'HEAD', $searchBy('Конст', 'Оси'));
$add('search: POST not allowed', 'POST', '/user/search', '{}');

// --- маршруты и методы ---
$add('routes: GET /', 'GET', '/');
$add('routes: GET /unknown', 'GET', '/unknown');
$add('routes: POST /unknown', 'POST', '/unknown', '{}');
$add('routes: GET /login (405)', 'GET', '/login');
$add('routes: PUT /login', 'PUT', '/login', '{}');
$add('routes: DELETE /login', 'DELETE', '/login');
$add('routes: OPTIONS /login', 'OPTIONS', '/login');
$add('routes: HEAD /login', 'HEAD', '/login');
$add('routes: GET /user/register (405)', 'GET', '/user/register');
$add('routes: PUT /user/register', 'PUT', '/user/register', '{}');
$add('routes: DELETE /user/register', 'DELETE', '/user/register');
$add('routes: OPTIONS /user/register', 'OPTIONS', '/user/register');
$add('routes: /login/ trailing slash', 'POST', '/login/', '{}');
$add('routes: /user/register/ trailing slash', 'POST', '/user/register/', '{}');
$add('routes: uppercase /LOGIN', 'POST', '/LOGIN', '{}');
$add('routes: double slash //login', 'POST', '//login', '{}');
$add('routes: /user', 'GET', '/user');
$add('routes: /user/', 'GET', '/user/');
$add('routes: /index.php', 'GET', '/index.php');
$add('routes: /user/get/../register', 'GET', '/user/get/../register');
$add('routes: percent-encoded /lo%67in', 'POST', '/lo%67in', '{}');

// Состояние сверки по приложениям (заполняется перед запуском кейсов, см. «Подготовка состояния» ниже).
$state = [];

/** Идентификаторы постов в ленте пользователя A в приложении $app. */
$feedPostIds = static function (string $app) use (&$state, $apps): array {
    $response = send($apps[$app], 'GET', '/post/feed?limit=100', null, null, 'Bearer ' . $state[$app]['tokenA']);
    $posts = json_decode($response['body'], true);

    return $response['status'] === 200 && is_array($posts) ? array_column($posts, 'id') : [];
};

/** Лента итогово согласована: ждём состояния, а не гадаем с паузами. Не дождались — ошибка запуска. */
$waitUntil = static function (string $app, string $what, callable $condition): void {
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        if ($condition()) {
            return;
        }
        usleep(100_000);
    }
    fwrite(STDERR, sprintf("%s: не дождались: %s\n", $app, $what));
    exit(2);
};

// =====================================================================================================================
// Друзья, посты и лента (ДЗ «Лента постов»).
// Подготовка состояния (ниже, перед запуском): пользователь A (из регистрации выше) и пользователь B; B создал посты
// {post1}, {post2}, {post3}; A добавил B в друзья. Лента A состоит ровно из этих трёх постов, пока кейсы не меняют дружбу
// (это делают только кейсы в самом конце). Остальные кейсы изменяют лишь собственные посты A: они в ленту A не входят.
// =====================================================================================================================
$authA = 'Bearer {tokenA}';
$authB = 'Bearer {tokenB}';
$garbage = 'Bearer garbage';
$unauthorized = [
    'no token' => null,
    'garbage token' => $garbage,
    'basic scheme' => 'Basic {tokenA}',
    'empty bearer' => 'Bearer ',
    'two spaces' => 'Bearer  {tokenA}',
    'token without scheme' => '{tokenA}',
    'truncated token' => 'Bearer v1.{tokenA}',
];
$postText = static fn (string $text): string => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);

// --- друзья: добавление ---
$addApi('friend set: success', 'PUT', '/friend/set/{friendId}', null, $authA);
$addApi('friend set: repeated call is idempotent', 'PUT', '/friend/set/{friendId}', null, $authA, ['repeat' => 2]);
$addApi('friend set: uppercase id', 'PUT', '/friend/set/{friendIdUpper}', null, $authA);
$addApi('friend set: lowercase bearer scheme', 'PUT', '/friend/set/{friendId}', null, 'bearer {tokenA}');
$addApi('friend set: body is ignored', 'PUT', '/friend/set/{friendId}', '{"x":1}', $authA);
$addApi('friend set: yourself', 'PUT', '/friend/set/{userId}', null, $authA);
$addApi('friend set: unknown user', 'PUT', '/friend/set/99999999-9999-4999-8999-999999999999', null, $authA);
$addApi('friend set: invalid id', 'PUT', '/friend/set/not-a-uuid', null, $authA);
$addApi('friend set: numeric id', 'PUT', '/friend/set/123', null, $authA);
$addApi('friend set: id with unicode', 'PUT', '/friend/set/%D0%B8%D0%B4', null, $authA);
$addApi('friend set: id without dashes', 'PUT', '/friend/set/99999999999949998999999999999999', null, $authA);
foreach ($unauthorized as $label => $header) {
    $addApi("friend set: $label", 'PUT', '/friend/set/{friendId}', null, $header);
}
$addApi('friend set: authorization is checked before the id', 'PUT', '/friend/set/not-a-uuid', null, $garbage);
$addApi('friend set: trailing slash', 'PUT', '/friend/set/{friendId}/', null, $authA);
$addApi('friend set: extra segment', 'PUT', '/friend/set/{friendId}/extra', null, $authA);
$addApi('friend set: no id', 'PUT', '/friend/set/', null, $authA);
$addApi('friend set: no id and no slash', 'PUT', '/friend/set', null, $authA);
foreach (['GET', 'POST', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'] as $method) {
    $addApi("friend set: $method not allowed", $method, '/friend/set/{friendId}', null, $authA);
}

// --- друзья: удаление (успешное удаление меняет ленту, оно в конце) ---
$addApi('friend delete: stranger is not an error', 'PUT', '/friend/delete/99999999-9999-4999-8999-999999999999', null, $authA);
$addApi('friend delete: yourself is not an error', 'PUT', '/friend/delete/{userId}', null, $authA);
$addApi('friend delete: invalid id', 'PUT', '/friend/delete/not-a-uuid', null, $authA);
$addApi('friend delete: numeric id', 'PUT', '/friend/delete/123', null, $authA);
foreach ($unauthorized as $label => $header) {
    $addApi("friend delete: $label", 'PUT', '/friend/delete/{friendId}', null, $header);
}
$addApi('friend delete: authorization is checked before the id', 'PUT', '/friend/delete/not-a-uuid', null, $garbage);
$addApi('friend delete: trailing slash', 'PUT', '/friend/delete/{friendId}/', null, $authA);
$addApi('friend delete: no id', 'PUT', '/friend/delete/', null, $authA);
foreach (['GET', 'POST', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
    $addApi("friend delete: $method not allowed", $method, '/friend/delete/{friendId}', null, $authA);
}

// --- посты: создание (автор A) ---
$addApi('post create: success', 'POST', '/post/create', $postText('Привет, мир'), $authA, ['fetchCreated' => 'post']);
$addApi('post create: text is trimmed', 'POST', '/post/create', $postText("  \n Пост с пробелами \t "), $authA, ['fetchCreated' => 'post']);
$addApi('post create: line breaks are kept', 'POST', '/post/create', $postText("Первая\n\nТретья"), $authA, ['fetchCreated' => 'post']);
$addApi('post create: CRLF becomes LF', 'POST', '/post/create', $postText("Первая\r\nВторая"), $authA, ['fetchCreated' => 'post']);
$addApi('post create: unicode and quotes', 'POST', '/post/create', $postText("Эмодзи 🙂 «ёлочки» \"кавычки\" '; DROP TABLE posts; --"), $authA, ['fetchCreated' => 'post']);
$addApi('post create: 2000 ASCII characters', 'POST', '/post/create', $postText(str_repeat('a', 2000)), $authA, ['fetchCreated' => 'post']);
$addApi('post create: 2000 multibyte characters', 'POST', '/post/create', $postText(str_repeat('я', 2000)), $authA, ['fetchCreated' => 'post']);
$addApi('post create: 2001 characters', 'POST', '/post/create', $postText(str_repeat('a', 2001)), $authA);
$addApi('post create: 2001 multibyte characters', 'POST', '/post/create', $postText(str_repeat('я', 2001)), $authA);
$addApi('post create: extra unknown field', 'POST', '/post/create', '{"text":"x","unknown":1}', $authA, ['fetchCreated' => 'post']);
$addApi('post create: empty text', 'POST', '/post/create', $postText(''), $authA);
$addApi('post create: blank text', 'POST', '/post/create', $postText("   \n\t "), $authA);
$addApi('post create: missing text', 'POST', '/post/create', '{}', $authA);
$addApi('post create: text number', 'POST', '/post/create', '{"text":123}', $authA);
$addApi('post create: text null', 'POST', '/post/create', '{"text":null}', $authA);
$addApi('post create: text array', 'POST', '/post/create', '{"text":["x"]}', $authA);
$addApi('post create: text object', 'POST', '/post/create', '{"text":{"a":1}}', $authA);
$addApi('post create: text bool', 'POST', '/post/create', '{"text":true}', $authA);
$addApi('post create: tab inside', 'POST', '/post/create', $postText("до\tпосле"), $authA);
$addApi('post create: escape character inside', 'POST', '/post/create', '{"text":"до\u001bпосле"}', $authA);
$addApi('post create: null character inside', 'POST', '/post/create', '{"text":"до\u0000после"}', $authA);
$addApi('post create: lone carriage return inside', 'POST', '/post/create', $postText("до\rпосле"), $authA);
$addApi('post create: invalid UTF-8 in body', 'POST', '/post/create', "{\"text\":\"\xC3\x28\"}", $authA);
$addApi('post create: body is not JSON', 'POST', '/post/create', 'not json', $authA);
$addApi('post create: body truncated JSON', 'POST', '/post/create', '{"text":', $authA);
$addApi('post create: empty body', 'POST', '/post/create', '', $authA);
$addApi('post create: JSON array body', 'POST', '/post/create', '[]', $authA);
$addApi('post create: JSON string body', 'POST', '/post/create', '"x"', $authA);
$addApi('post create: JSON null body', 'POST', '/post/create', 'null', $authA);
$addApi('post create: no body at all', 'POST', '/post/create', null, $authA);
$addApi('post create: text/plain content type', 'POST', '/post/create', $postText('x'), $authA, ['type' => 'text/plain', 'fetchCreated' => 'post']);
$addApi('post create: no content type', 'POST', '/post/create', $postText('x'), $authA, ['type' => null, 'fetchCreated' => 'post']);
$addApi('post create: form content type', 'POST', '/post/create', $postText('x'), $authA, ['type' => 'application/x-www-form-urlencoded', 'fetchCreated' => 'post']);
foreach ($unauthorized as $label => $header) {
    $addApi("post create: $label", 'POST', '/post/create', $postText('x'), $header);
}
$addApi('post create: authorization is checked before the body', 'POST', '/post/create', 'not json', $garbage);
$addApi('post create: authorization is checked before validation', 'POST', '/post/create', $postText(''), $garbage);
$addApi('post create: valid token, malformed JSON', 'POST', '/post/create', 'not json', $authA);
$addApi('post create: trailing slash', 'POST', '/post/create/', $postText('x'), $authA);
foreach (['GET', 'PUT', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
    $addApi("post create: $method not allowed", $method, '/post/create', null, $authA);
}

// --- посты: получение (публичное) ---
$addApi('post get: success', 'GET', '/post/get/{post1}');
$addApi('post get: uppercase id', 'GET', '/post/get/{post1Upper}');
$addApi('post get: not found', 'GET', '/post/get/99999999-9999-4999-8999-999999999999');
$addApi('post get: invalid id', 'GET', '/post/get/not-a-uuid');
$addApi('post get: numeric id', 'GET', '/post/get/123');
$addApi('post get: id with unicode', 'GET', '/post/get/%D0%B8%D0%B4');
$addApi('post get: id without dashes', 'GET', '/post/get/99999999999949998999999999999999');
$addApi('post get: trailing slash', 'GET', '/post/get/{post1}/');
$addApi('post get: extra segment', 'GET', '/post/get/{post1}/extra');
$addApi('post get: no id', 'GET', '/post/get/');
$addApi('post get: no id and no slash', 'GET', '/post/get');
$addApi('post get: query string ignored', 'GET', '/post/get/{post1}?x=1');
$addApi('post get: valid token is ignored', 'GET', '/post/get/{post1}', null, $authA);
$addApi('post get: garbage token is ignored', 'GET', '/post/get/{post1}', null, $garbage);
foreach (['HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'] as $method) {
    $addApi("post get: $method not allowed", $method, '/post/get/{post1}');
}

// --- посты: изменение (свежий пост A) ---
$update = static fn (string $id, string $text): string => json_encode(['id' => $id, 'text' => $text], JSON_UNESCAPED_UNICODE);
$addApi('post update: success', 'PUT', '/post/update', $update('{fresh}', 'Новый текст'), $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: text is trimmed', 'PUT', '/post/update', $update('{fresh}', "  Обрезанный \n"), $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: uppercase id', 'PUT', '/post/update', $update('{freshUpper}', 'Через верхний регистр'), $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: same text', 'PUT', '/post/update', $update('{fresh}', 'Fresh post'), $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: repeated call', 'PUT', '/post/update', $update('{fresh}', 'Дважды'), $authA, ['fresh' => true, 'repeat' => 2, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: extra unknown field', 'PUT', '/post/update', '{"id":"{fresh}","text":"x","unknown":1}', $authA, ['fresh' => true]);
$addApi('post update: foreign post looks missing', 'PUT', '/post/update', $update('{post1}', 'Взлом'), $authA, ['followUp' => '/post/get/{post1}']);
$addApi('post update: foreign post with the author token of another user', 'PUT', '/post/update', $update('{fresh}', 'Чужой токен'), $authB, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: unknown post', 'PUT', '/post/update', $update('99999999-9999-4999-8999-999999999999', 'x'), $authA);
$addApi('post update: invalid id', 'PUT', '/post/update', $update('not-a-uuid', 'x'), $authA);
$addApi('post update: numeric id value', 'PUT', '/post/update', '{"id":123,"text":"x"}', $authA);
$addApi('post update: id null', 'PUT', '/post/update', '{"id":null,"text":"x"}', $authA);
$addApi('post update: id array', 'PUT', '/post/update', '{"id":["x"],"text":"x"}', $authA);
$addApi('post update: missing id', 'PUT', '/post/update', '{"text":"x"}', $authA);
$addApi('post update: missing text', 'PUT', '/post/update', '{"id":"{fresh}"}', $authA, ['fresh' => true]);
$addApi('post update: text number', 'PUT', '/post/update', '{"id":"{fresh}","text":5}', $authA, ['fresh' => true]);
$addApi('post update: empty text', 'PUT', '/post/update', $update('{fresh}', ''), $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: blank text', 'PUT', '/post/update', $update('{fresh}', "  \n "), $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: 2000 characters', 'PUT', '/post/update', $update('{fresh}', str_repeat('я', 2000)), $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post update: 2001 characters', 'PUT', '/post/update', $update('{fresh}', str_repeat('я', 2001)), $authA, ['fresh' => true]);
$addApi('post update: tab inside', 'PUT', '/post/update', $update('{fresh}', "до\tпосле"), $authA, ['fresh' => true]);
$addApi('post update: malformed id is reported before invalid text', 'PUT', '/post/update', $update('not-a-uuid', ''), $authA);
$addApi('post update: invalid text of a foreign post is reported before not found', 'PUT', '/post/update', $update('{post1}', ''), $authA);
$addApi('post update: body is not JSON', 'PUT', '/post/update', 'not json', $authA);
$addApi('post update: empty body', 'PUT', '/post/update', '', $authA);
$addApi('post update: JSON array body', 'PUT', '/post/update', '[]', $authA);
$addApi('post update: empty object body', 'PUT', '/post/update', '{}', $authA);
$addApi('post update: no body at all', 'PUT', '/post/update', null, $authA);
$addApi('post update: text/plain content type', 'PUT', '/post/update', $update('{fresh}', 'plain'), $authA, ['fresh' => true, 'type' => 'text/plain']);
foreach ($unauthorized as $label => $header) {
    $addApi("post update: $label", 'PUT', '/post/update', $update('{post1}', 'x'), $header);
}
$addApi('post update: authorization is checked before the body', 'PUT', '/post/update', 'not json', $garbage);
$addApi('post update: authorization is checked before validation', 'PUT', '/post/update', $update('not-a-uuid', ''), $garbage);
$addApi('post update: valid token, malformed JSON', 'PUT', '/post/update', 'not json', $authA);
$addApi('post update: trailing slash', 'PUT', '/post/update/', $update('{post1}', 'x'), $authA);
$addApi('post update: id in the path', 'PUT', '/post/update/{post1}', '{"text":"x"}', $authA);
foreach (['GET', 'POST', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
    $addApi("post update: $method not allowed", $method, '/post/update', null, $authA);
}

// --- посты: удаление (свежий пост A) ---
$addApi('post delete: success', 'PUT', '/post/delete/{fresh}', null, $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post delete: repeated call is not found', 'PUT', '/post/delete/{fresh}', null, $authA, ['fresh' => true, 'repeat' => 2]);
$addApi('post delete: uppercase id', 'PUT', '/post/delete/{freshUpper}', null, $authA, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post delete: body is ignored', 'PUT', '/post/delete/{fresh}', '{"x":1}', $authA, ['fresh' => true]);
$addApi('post delete: foreign post looks missing', 'PUT', '/post/delete/{post1}', null, $authA, ['followUp' => '/post/get/{post1}']);
$addApi('post delete: own post with the token of another user', 'PUT', '/post/delete/{fresh}', null, $authB, ['fresh' => true, 'followUp' => '/post/get/{fresh}']);
$addApi('post delete: unknown post', 'PUT', '/post/delete/99999999-9999-4999-8999-999999999999', null, $authA);
$addApi('post delete: invalid id', 'PUT', '/post/delete/not-a-uuid', null, $authA);
$addApi('post delete: numeric id', 'PUT', '/post/delete/123', null, $authA);
$addApi('post delete: id with unicode', 'PUT', '/post/delete/%D0%B8%D0%B4', null, $authA);
foreach ($unauthorized as $label => $header) {
    $addApi("post delete: $label", 'PUT', '/post/delete/{post1}', null, $header);
}
$addApi('post delete: authorization is checked before the id', 'PUT', '/post/delete/not-a-uuid', null, $garbage);
$addApi('post delete: trailing slash', 'PUT', '/post/delete/{post1}/', null, $authA);
$addApi('post delete: no id', 'PUT', '/post/delete/', null, $authA);
foreach (['GET', 'POST', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
    $addApi("post delete: $method not allowed", $method, '/post/delete/{post1}', null, $authA);
}

// --- лента: A дружит с B, у B три поста (новые первыми: post3, post2, post1) ---
$feed = static fn (string $query = ''): string => '/post/feed' . ($query === '' ? '' : '?' . $query);
$addApi('feed: default page', 'GET', $feed(), null, $authA);
$addApi('feed: limit 1', 'GET', $feed('limit=1'), null, $authA);
$addApi('feed: limit 2 offset 1', 'GET', $feed('limit=2&offset=1'), null, $authA);
$addApi('feed: offset 1', 'GET', $feed('offset=1'), null, $authA);
$addApi('feed: offset 2 limit 100', 'GET', $feed('offset=2&limit=100'), null, $authA);
$addApi('feed: offset at the end', 'GET', $feed('offset=3'), null, $authA);
$addApi('feed: offset 1000', 'GET', $feed('offset=1000&limit=100'), null, $authA);
$addApi('feed: huge offset', 'GET', $feed('offset=99999999999999999999999'), null, $authA);
$addApi('feed: limit 100', 'GET', $feed('limit=100'), null, $authA);
$addApi('feed: leading zeros', 'GET', $feed('offset=001&limit=010'), null, $authA);
$addApi('feed: unknown parameters are ignored', 'GET', $feed('x=1&offset=1'), null, $authA);
$addApi('feed: duplicate parameter', 'GET', $feed('limit=1&limit=2'), null, $authA);
$addApi('feed: limit 101', 'GET', $feed('limit=101'), null, $authA);
$addApi('feed: limit 0', 'GET', $feed('limit=0'), null, $authA);
$addApi('feed: negative limit', 'GET', $feed('limit=-1'), null, $authA);
$addApi('feed: huge limit', 'GET', $feed('limit=99999999999999999999999'), null, $authA);
$addApi('feed: limit letters', 'GET', $feed('limit=abc'), null, $authA);
$addApi('feed: empty limit', 'GET', $feed('limit='), null, $authA);
$addApi('feed: limit fraction', 'GET', $feed('limit=1.5'), null, $authA);
$addApi('feed: limit exponent', 'GET', $feed('limit=1e1'), null, $authA);
$addApi('feed: limit with space', 'GET', $feed('limit=%205'), null, $authA);
$addApi('feed: limit with plus sign', 'GET', $feed('limit=%2B5'), null, $authA);
$addApi('feed: limit array', 'GET', $feed('limit[]=1'), null, $authA);
$addApi('feed: negative offset', 'GET', $feed('offset=-1'), null, $authA);
$addApi('feed: offset letters', 'GET', $feed('offset=abc'), null, $authA);
$addApi('feed: empty offset', 'GET', $feed('offset='), null, $authA);
$addApi('feed: offset fraction', 'GET', $feed('offset=1.5'), null, $authA);
$addApi('feed: offset array', 'GET', $feed('offset[]=1'), null, $authA);
$addApi('feed: invalid offset is reported before invalid limit', 'GET', $feed('offset=-1&limit=0'), null, $authA);
$addApi('feed: friendless user has an empty feed', 'GET', $feed(), null, $authB);
foreach ($unauthorized as $label => $header) {
    $addApi("feed: $label", 'GET', $feed(), null, $header);
}
$addApi('feed: authorization is checked before the page parameters', 'GET', $feed('limit=0'), null, $garbage);
$addApi('feed: trailing slash', 'GET', '/post/feed/', null, $authA);
foreach (['POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
    $addApi("feed: $method not allowed", $method, $feed(), null, $authA);
}

// --- дружба меняет ленту (в конце: после этих кейсов лента A меняется) ---
$addApi('friend delete: success', 'PUT', '/friend/delete/{friendId}', null, $authA);
$addApi('friend delete: repeated call is idempotent', 'PUT', '/friend/delete/{friendId}', null, $authA, ['repeat' => 2]);
$step('wait until the feed is empty after the friend was removed', static function (string $app) use (&$state, $apps, $feedPostIds, $waitUntil): void {
    $waitUntil($app, 'feed of A is empty', static fn (): bool => $feedPostIds($app) === []);
});
$addApi('feed: empty after the friend was removed', 'GET', $feed(), null, $authA);
$addApi('friend set: again after removal', 'PUT', '/friend/set/{friendId}', null, $authA);
$step('wait until the feed is restored by the backfill', static function (string $app) use ($feedPostIds, $waitUntil): void {
    $waitUntil($app, 'feed of A has three posts again', static fn (): bool => count($feedPostIds($app)) === 3);
});
$addApi('feed: restored after the friend was added again', 'GET', $feed(), null, $authA);

// =====================================================================================================================
// Диалоги (ДЗ «Шардинг диалогов»). Подготовка состояния (ниже): в диалоге A и B три сообщения — {message1} от B,
// {message2} от A, {message3} от B. Сначала кейсы чтения этого диалога, затем отправка (она его дополняет).
// =====================================================================================================================
$list = static fn (string $userId, string $query = ''): string => '/dialog/' . $userId . '/list' . ($query === '' ? '' : '?' . $query);
$unknownUser = '99999999-9999-4999-8999-999999999999';

// --- диалог: чтение ---
$addApi('dialog list: default page', 'GET', $list('{friendId}'), null, $authA);
$addApi('dialog list: same dialog for the other side', 'GET', $list('{userId}'), null, $authB);
$addApi('dialog list: uppercase userId', 'GET', $list('{friendIdUpper}'), null, $authA);
$addApi('dialog list: limit 1', 'GET', $list('{friendId}', 'limit=1'), null, $authA);
$addApi('dialog list: limit 2', 'GET', $list('{friendId}', 'limit=2'), null, $authA);
$addApi('dialog list: limit 100', 'GET', $list('{friendId}', 'limit=100'), null, $authA);
$addApi('dialog list: leading zeros', 'GET', $list('{friendId}', 'limit=002'), null, $authA);
$addApi('dialog list: before the newest', 'GET', $list('{friendId}', 'before={message3}'), null, $authA);
$addApi('dialog list: before with limit', 'GET', $list('{friendId}', 'limit=1&before={message3}'), null, $authA);
$addApi('dialog list: before the oldest', 'GET', $list('{friendId}', 'before={message1}'), null, $authA);
$addApi('dialog list: uppercase before', 'GET', $list('{friendId}', 'before={message3Upper}'), null, $authA);
$addApi('dialog list: before of the smallest id', 'GET', $list('{friendId}', 'before=00000000-0000-0000-0000-000000000000'), null, $authA);
$addApi('dialog list: before of the largest id', 'GET', $list('{friendId}', 'before=ffffffff-ffff-ffff-ffff-ffffffffffff'), null, $authA);
$addApi('dialog list: unknown parameters are ignored', 'GET', $list('{friendId}', 'x=1&offset=1'), null, $authA);
$addApi('dialog list: duplicate parameter', 'GET', $list('{friendId}', 'limit=1&limit=2'), null, $authA);
$addApi('dialog list: unknown user', 'GET', $list($unknownUser), null, $authA);
$addApi('dialog list: yourself', 'GET', $list('{userId}'), null, $authA);
$addApi('dialog list: invalid userId', 'GET', $list('not-a-uuid'), null, $authA);
$addApi('dialog list: numeric userId', 'GET', $list('123'), null, $authA);
$addApi('dialog list: userId with unicode', 'GET', $list('%D0%B8%D0%B4'), null, $authA);
foreach (['limit=0', 'limit=101', 'limit=-1', 'limit=abc', 'limit=', 'limit=1.5', 'limit=1e1', 'limit=%205', 'limit=%2B5', 'limit[]=1', 'limit=99999999999999999999999'] as $query) {
    $addApi("dialog list: $query", 'GET', $list('{friendId}', $query), null, $authA);
}
foreach (['before=', 'before=abc', 'before=123', 'before[]=x', 'before=%D0%B8%D0%B4', 'before={message1}%0A'] as $query) {
    $addApi("dialog list: $query", 'GET', $list('{friendId}', $query), null, $authA);
}
$addApi('dialog list: invalid userId is reported before invalid limit', 'GET', $list('not-a-uuid', 'limit=0'), null, $authA);
$addApi('dialog list: yourself is reported before invalid limit', 'GET', $list('{userId}', 'limit=0'), null, $authA);
$addApi('dialog list: invalid limit is reported before invalid before', 'GET', $list('{friendId}', 'limit=0&before=abc'), null, $authA);
$addApi('dialog list: non-string limit and before', 'GET', $list('{friendId}', 'limit[]=1&before[]=x'), null, $authA);
foreach ($unauthorized as $label => $header) {
    $addApi("dialog list: $label", 'GET', $list('{friendId}'), null, $header);
}
$addApi('dialog list: authorization is checked before parameters', 'GET', $list('not-a-uuid', 'limit=0'), null, $garbage);
$addApi('dialog list: trailing slash', 'GET', '/dialog/{friendId}/list/', null, $authA);
$addApi('dialog list: extra segment', 'GET', '/dialog/{friendId}/list/extra', null, $authA);
$addApi('dialog list: no userId', 'GET', '/dialog//list', null, $authA);
$addApi('dialog list: no userId segment', 'GET', '/dialog/list', null, $authA);
foreach (['POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
    $addApi("dialog list: $method not allowed", $method, $list('{friendId}'), null, $authA);
}

// --- диалог: отправка ---
$send = '/dialog/{friendId}/send';
$addApi('dialog send: success', 'POST', $send, $postText('Привет, как дела?'), $authA);
$addApi('dialog send: reply', 'POST', '/dialog/{userId}/send', $postText('Хорошо'), $authB);
$addApi('dialog send: uppercase userId', 'POST', '/dialog/{friendIdUpper}/send', $postText('Верхний регистр'), $authA);
$addApi('dialog send: text is trimmed', 'POST', $send, $postText("  \n С пробелами \t "), $authA);
$addApi('dialog send: line breaks and CRLF', 'POST', $send, $postText("Первая\r\n\nТретья"), $authA);
$addApi('dialog send: unicode and quotes', 'POST', $send, $postText("Эмодзи 🙂 «ёлочки» \"кавычки\" '; DROP TABLE dialog_messages; --"), $authA);
$addApi('dialog send: 2000 multibyte characters', 'POST', $send, $postText(str_repeat('я', 2000)), $authA);
$addApi('dialog send: extra unknown field', 'POST', $send, '{"text":"x","to":"{userId}"}', $authA);
$addApi('dialog send: text/plain content type', 'POST', $send, $postText('plain'), $authA, ['type' => 'text/plain']);
$addApi('dialog send: no content type', 'POST', $send, $postText('без типа'), $authA, ['type' => null]);
$addApi('dialog send: repeated call creates two messages', 'POST', $send, $postText('Дважды'), $authA, ['repeat' => 2]);
$addApi('dialog send: 2001 characters', 'POST', $send, $postText(str_repeat('я', 2001)), $authA);
$addApi('dialog send: empty text', 'POST', $send, $postText(''), $authA);
$addApi('dialog send: blank text', 'POST', $send, $postText("   \n\t "), $authA);
$addApi('dialog send: missing text', 'POST', $send, '{}', $authA);
$addApi('dialog send: text number', 'POST', $send, '{"text":123}', $authA);
$addApi('dialog send: text null', 'POST', $send, '{"text":null}', $authA);
$addApi('dialog send: text array', 'POST', $send, '{"text":["x"]}', $authA);
$addApi('dialog send: tab inside', 'POST', $send, $postText("до\tпосле"), $authA);
$addApi('dialog send: null character inside', 'POST', $send, '{"text":"до\u0000после"}', $authA);
$addApi('dialog send: invalid UTF-8 in body', 'POST', $send, "{\"text\":\"\xC3\x28\"}", $authA);
$addApi('dialog send: body is not JSON', 'POST', $send, 'not json', $authA);
$addApi('dialog send: empty body', 'POST', $send, '', $authA);
$addApi('dialog send: JSON array body', 'POST', $send, '[]', $authA);
$addApi('dialog send: JSON null body', 'POST', $send, 'null', $authA);
$addApi('dialog send: no body at all', 'POST', $send, null, $authA);
$addApi('dialog send: yourself', 'POST', '/dialog/{userId}/send', $postText('x'), $authA);
$addApi('dialog send: unknown user', 'POST', "/dialog/$unknownUser/send", $postText('x'), $authA);
$addApi('dialog send: invalid userId', 'POST', '/dialog/not-a-uuid/send', $postText('x'), $authA);
$addApi('dialog send: missing text is reported before invalid userId', 'POST', '/dialog/not-a-uuid/send', '{}', $authA);
$addApi('dialog send: invalid userId is reported before empty text', 'POST', '/dialog/not-a-uuid/send', $postText(''), $authA);
$addApi('dialog send: empty text is reported before yourself', 'POST', '/dialog/{userId}/send', $postText(''), $authA);
$addApi('dialog send: empty text is reported before unknown user', 'POST', "/dialog/$unknownUser/send", $postText(''), $authA);
foreach ($unauthorized as $label => $header) {
    $addApi("dialog send: $label", 'POST', $send, $postText('x'), $header);
}
$addApi('dialog send: authorization is checked before the body', 'POST', $send, 'not json', $garbage);
$addApi('dialog send: trailing slash', 'POST', "$send/", $postText('x'), $authA);
$addApi('dialog send: no userId', 'POST', '/dialog//send', $postText('x'), $authA);
foreach (['GET', 'PUT', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
    $addApi("dialog send: $method not allowed", $method, $send, null, $authA);
}

// --- диалог после отправок: тексты, участники и порядок одинаковы, отклонённые сообщения не сохранены ---
$addApi('dialog list: after sending', 'GET', $list('{friendId}', 'limit=100'), null, $authA);

// --- Подготовка состояния для кейсов друзей, постов и ленты ---
// В каждом приложении: пользователь A (зарегистрирован выше) получает токен, регистрируется пользователь B, B создаёт три поста,
// A создаёт один пост и добавляет B в друзья. Ждём, пока лента A не наполнится.
$loginToken = static function (string $name, string $baseUrl, string $userId) use ($password): string {
    $response = send($baseUrl, 'POST', '/login', json_encode(['userId' => $userId, 'password' => $password]), JSON_TYPE);
    $token = json_decode($response['body'], true)['token'] ?? null;
    if ($response['status'] !== 200 || !is_string($token)) {
        fwrite(STDERR, sprintf("%s: не удалось войти (%d): %s\n", $name, $response['status'], $response['body']));
        exit(2);
    }

    return $token;
};
$createdId = static function (string $name, array $response, int $expectedStatus): string {
    $id = json_decode($response['body'], true)['id'] ?? null;
    if ($response['status'] !== $expectedStatus || !is_string($id)) {
        fwrite(STDERR, sprintf("%s: подготовка состояния не удалась (%d): %s\n", $name, $response['status'], $response['body']));
        exit(2);
    }

    return $id;
};
foreach ($apps as $name => $baseUrl) {
    $tokenA = $loginToken($name, $baseUrl, $users[$name]);
    $friendId = $createdId($name, send($baseUrl, 'POST', '/user/register', payload(['firstName' => 'Anna', 'lastName' => 'Sidorova']), JSON_TYPE), 201);
    $tokenB = $loginToken($name, $baseUrl, $friendId);

    $posts = [];
    foreach ([1, 2, 3] as $number) {
        $posts[$number] = $createdId($name, send($baseUrl, 'POST', '/post/create', json_encode(['text' => 'Compare post ' . $number]), JSON_TYPE, 'Bearer ' . $tokenB), 201);
        usleep(10_000);
    }
    $ownPost = $createdId($name, send($baseUrl, 'POST', '/post/create', json_encode(['text' => 'Own post of A']), JSON_TYPE, 'Bearer ' . $tokenA), 201);

    $friendship = send($baseUrl, 'PUT', '/friend/set/' . $friendId, null, null, 'Bearer ' . $tokenA);
    if ($friendship['status'] !== 204) {
        fwrite(STDERR, sprintf("%s: не удалось добавить друга (%d): %s\n", $name, $friendship['status'], $friendship['body']));
        exit(2);
    }

    // Диалог A и B: три сообщения по очереди, с паузой, чтобы они получили id из разных миллисекунд.
    foreach ([[$tokenB, $users[$name]], [$tokenA, $friendId], [$tokenB, $users[$name]]] as $index => [$token, $recipient]) {
        $sent = send($baseUrl, 'POST', "/dialog/$recipient/send", json_encode(['text' => 'Compare message ' . ($index + 1)]), JSON_TYPE, 'Bearer ' . $token);
        if ($sent['status'] !== 204) {
            fwrite(STDERR, sprintf("%s: не удалось отправить сообщение (%d): %s\n", $name, $sent['status'], $sent['body']));
            exit(2);
        }
        usleep(10_000);
    }
    $dialog = send($baseUrl, 'GET', "/dialog/$friendId/list", null, null, 'Bearer ' . $tokenA);
    $messageIds = array_column(json_decode($dialog['body'], true) ?? [], 'id');
    if ($dialog['status'] !== 200 || count($messageIds) !== 3) {
        fwrite(STDERR, sprintf("%s: не удалось прочитать диалог (%d): %s\n", $name, $dialog['status'], $dialog['body']));
        exit(2);
    }
    $messages = [1 => $messageIds[0], 2 => $messageIds[1], 3 => $messageIds[2]];

    $state[$name] = ['userId' => $users[$name], 'friendId' => $friendId, 'tokenA' => $tokenA, 'tokenB' => $tokenB, 'posts' => $posts, 'ownPost' => $ownPost, 'messages' => $messages];
}
foreach (array_keys($apps) as $name) {
    $waitUntil($name, 'лента A содержит три поста', static fn (): bool => count($feedPostIds($name)) === 3);
}

$replacements = static function (array $state, ?string $fresh): array {
    $map = [
        '{userId}' => $state['userId'],
        '{userIdUpper}' => strtoupper($state['userId']),
        '{friendId}' => $state['friendId'],
        '{friendIdUpper}' => strtoupper($state['friendId']),
        '{post1}' => $state['posts'][1],
        '{post1Upper}' => strtoupper($state['posts'][1]),
        '{post2}' => $state['posts'][2],
        '{post3}' => $state['posts'][3],
        '{message1}' => $state['messages'][1],
        '{message3}' => $state['messages'][3],
        '{message3Upper}' => strtoupper($state['messages'][3]),
        '{tokenA}' => $state['tokenA'],
        '{tokenB}' => $state['tokenB'],
    ];
    if ($fresh !== null) {
        $map['{fresh}'] = $fresh;
        $map['{freshUpper}'] = strtoupper($fresh);
    }

    return $map;
};
$fill = static fn (?string $text, array $map): ?string => $text === null ? null : strtr($text, $map);

$failures = 0;
$known = 0;
$stale = 0;
foreach ($cases as $case) {
    if (isset($case['step'])) {
        foreach (array_keys($apps) as $name) {
            ($case['step'])($name);
        }
        continue;
    }

    $results = [];
    foreach ($apps as $name => $baseUrl) {
        $current = $state[$name];

        $fresh = null;
        if ($case['fresh']) {
            $fresh = $createdId($name, send($baseUrl, 'POST', '/post/create', json_encode(['text' => 'Fresh post']), JSON_TYPE, 'Bearer ' . $current['tokenA']), 201);
        }
        $map = $replacements($current, $fresh);

        $knownIds = [
            '<userId>' => $current['userId'],
            '<friendId>' => $current['friendId'],
            '<post1>' => $current['posts'][1],
            '<post2>' => $current['posts'][2],
            '<post3>' => $current['posts'][3],
            '<ownPost>' => $current['ownPost'],
            '<message1>' => $current['messages'][1],
            '<message2>' => $current['messages'][2],
            '<message3>' => $current['messages'][3],
        ];
        if ($fresh !== null) {
            $knownIds['<fresh>'] = $fresh;
        }

        $path = $fill($case['path'], $map);
        $body = $fill($case['body'], $map);
        $authorization = $fill($case['auth'], $map);

        $parts = [];
        for ($attempt = 0; $attempt < $case['repeat']; $attempt++) {
            $parts[] = send($baseUrl, $case['method'], $path, $body, $case['type'], $authorization);
        }
        $response = $parts[0];

        if ($case['fetchCreated'] && $response['status'] === 201) {
            $createdEntityId = json_decode($response['body'], true)['id'] ?? null;
            if (is_string($createdEntityId)) {
                $knownIds['<created>'] = $createdEntityId;
                $parts[] = send($baseUrl, 'GET', ($case['fetchCreated'] === 'post' ? '/post/get/' : '/user/get/') . $createdEntityId, null, null);
            }
        }

        if ($case['followUp'] !== null) {
            $parts[] = send($baseUrl, 'GET', $fill($case['followUp'], $map), null, null);
        }

        $results[$name] = array_map(static fn (array $part): array => [
            'status' => $part['status'],
            'contentType' => $part['contentType'],
            'allow' => $part['allow'],
            'body' => canonical(normalize($part['body'], $knownIds)),
        ], $parts);
    }

    $reason = KNOWN_DIFFERENCES[$case['name']] ?? null;

    if ($results['native'] === $results['symfony']) {
        if ($reason !== null) {
            $stale++;
            printf("  STALE %s: расхождения больше нет, удалите запись из KNOWN_DIFFERENCES\n", $case['name']);
            continue;
        }
        printf("  ok    %s\n", $case['name']);
        continue;
    }

    if ($reason !== null) {
        $known++;
        printf("  known %s   [%s %s]: %s\n", $case['name'], $case['method'], $case['path'], $reason);
        continue;
    }

    $failures++;
    printf("  DIFF  %s   [%s %s]\n", $case['name'], $case['method'], $case['path']);
    foreach ($results as $name => $parts) {
        foreach ($parts as $index => $part) {
            printf("        %-8s%s status=%d content-type=%s allow=%s\n", $name, $index === 0 ? '' : sprintf(' (part %d)', $index + 1), $part['status'], $part['contentType'] ?: '-', $part['allow'] ?: '-');
            printf("                body: %s\n", str_replace("\n", ' ', substr($part['body'], 0, 300)));
        }
    }
}

foreach (array_diff(array_keys(KNOWN_DIFFERENCES), array_column($cases, 'name')) as $missing) {
    $stale++;
    printf("  STALE %s: кейса с таким именем нет, удалите запись из KNOWN_DIFFERENCES\n", $missing);
}

printf("\n%d cases, %d new differences, %d known, %d stale\n", count($cases), $failures, $known, $stale);
exit($failures === 0 && $stale === 0 ? 0 : 1);
