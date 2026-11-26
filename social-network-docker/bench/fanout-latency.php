<?php

declare(strict_types=1);

// Задержка fan-out: сколько проходит от создания поста до его появления в ленте подписчика, в зависимости от числа
// подписчиков автора. Запуск (через fanout-latency.sh): php fanout-latency.php <авторы.csv> <секрет> <итераций> <приложение=url>...
// Лента подписчика заранее материализована (иначе первое чтение собрало бы её из БД и скрыло fan-out). Подписчики двух видов:
// первый и последний по id: воркер обходит подписчиков автора порциями по порядку id, поэтому последний получает пост
// после обработки всех предыдущих порций. Для обычного автора разницы почти нет, для «знаменитости» она и есть цена fan-out.

if ($argc < 5) {
    fwrite(STDERR, "usage: fanout-latency.php <authors.csv> <secret> <iterations> <app=url>...\n");
    exit(2);
}

[$script, $csvPath, $secret, $iterations] = $argv;
$iterations = (int) $iterations;
$apps = [];
foreach (array_slice($argv, 4) as $pair) {
    [$name, $url] = explode('=', $pair, 2);
    $apps[$name] = rtrim($url, '/');
}

function token(string $id, string $secret): string
{
    return 'v1.' . $id . '.' . hash_hmac('sha256', $id, $secret);
}

/** @return array{int, string} статус и тело */
function call(string $method, string $url, string $token, ?string $body = null): array
{
    $handle = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token, 'Expect:'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return [$status, $response === false ? '' : (string) $response];
}

function percentile(array $values, float $p): float
{
    if ($values === []) {
        return NAN;
    }
    sort($values);

    return $values[(int) min(count($values) - 1, floor($p * count($values)))];
}

function summary(array $values): string
{
    return $values === [] ? '-' : sprintf('%.0f / %.0f / %.0f', percentile($values, 0.5), percentile($values, 0.95), max($values));
}

$classes = [];
foreach (file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$class, $author, $followers, $first, $last] = explode(',', $line);
    $classes[] = compact('class', 'author', 'followers', 'first', 'last');
}

printf("| приложение | автор | подписчиков | первый подписчик, мс (p50 / p95 / max) | последний подписчик, мс (p50 / p95 / max) | не дождались |\n");
printf("|---|---|---|---|---|---|\n");

foreach ($apps as $app => $baseUrl) {
    foreach ($classes as $row) {
        $authorToken = token($row['author'], $secret);
        $watchers = ['first' => token($row['first'], $secret), 'last' => token($row['last'], $secret)];

        // Лента подписчика материализуется до замера.
        foreach ($watchers as $watcherToken) {
            call('GET', $baseUrl . '/post/feed?limit=1', $watcherToken);
        }

        $latencies = ['first' => [], 'last' => []];
        $timeouts = 0;

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            [$status, $body] = call('POST', $baseUrl . '/post/create', $authorToken, json_encode(['text' => sprintf('Fan-out %s %d', $row['class'], $iteration)]));
            $postId = json_decode($body, true)['id'] ?? null;
            if ($status !== 201 || !is_string($postId)) {
                fwrite(STDERR, sprintf("%s %s: создать пост не удалось (%d): %s\n", $app, $row['class'], $status, $body));
                exit(2);
            }

            $startedAt = hrtime(true);
            $pending = $watchers;

            while ($pending !== [] && (hrtime(true) - $startedAt) / 1e9 < 30) {
                foreach ($pending as $kind => $watcherToken) {
                    [, $feed] = call('GET', $baseUrl . '/post/feed?limit=1', $watcherToken);
                    $posts = json_decode($feed, true);
                    if (is_array($posts) && ($posts[0]['id'] ?? null) === $postId) {
                        $latencies[$kind][] = (hrtime(true) - $startedAt) / 1e6;
                        unset($pending[$kind]);
                    }
                }
                usleep(2000);
            }

            $timeouts += count($pending);
            usleep(300_000);
        }

        printf("| %s | %s | %d | %s | %s | %d |\n", $app, $row['class'], $row['followers'], summary($latencies['first']), summary($latencies['last']), $timeouts);
    }
}
