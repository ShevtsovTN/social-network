<?php

declare(strict_types=1);

// Сверка двух приложений: одинаковые запросы в оба, сравнение статуса, Content-Type, Allow и тела.
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
function send(string $baseUrl, string $method, string $path, ?string $body, ?string $contentType): array
{
    $responseHeaders = [];
    $handle = curl_init($baseUrl . $path);
    $requestHeaders = ['Expect:'];
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
    $text = preg_replace('/(v1\.<userId>\.)[0-9a-f]{64}/', '$1<hmac>', $text);

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
$add = static function (string $name, string $method, string $path, ?string $body = null, ?string $type = JSON_TYPE, bool $fetchCreated = false) use (&$cases): void {
    $cases[] = compact('name', 'method', 'path', 'body', 'type', 'fetchCreated');
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

$failures = 0;
$known = 0;
$stale = 0;
foreach ($cases as $case) {
    $results = [];
    foreach ($apps as $name => $baseUrl) {
        $knownIds = ['<userId>' => $users[$name]];
        $path = str_replace(['{userId}', '{userIdUpper}'], [$users[$name], strtoupper($users[$name])], $case['path']);
        $body = $case['body'] === null ? null : str_replace(['{userId}', '{userIdUpper}'], [$users[$name], strtoupper($users[$name])], $case['body']);
        $response = send($baseUrl, $case['method'], $path, $body, $case['type']);
        $parts = [$response];

        if ($case['fetchCreated'] && $response['status'] === 201) {
            $createdId = json_decode($response['body'], true)['id'] ?? null;
            if (is_string($createdId)) {
                $knownIds['<created>'] = $createdId;
                $parts[] = send($baseUrl, 'GET', '/user/get/' . $createdId, null, null);
            }
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
            printf("        %-8s%s status=%d content-type=%s allow=%s\n", $name, $index === 0 ? '' : ' (get created)', $part['status'], $part['contentType'] ?: '-', $part['allow'] ?: '-');
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
