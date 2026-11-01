<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hermetica\Client;
use Hermetica\HermeticaException;

function check(bool $ok, string $what): void
{
    if (!$ok) { fwrite(STDERR, "FAIL: $what\n"); exit(1); }
    echo "ok - $what\n";
}

$port = random_int(20000, 40000);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(fn () => proc_terminate($server));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = "http://127.0.0.1:$port";
$run = bin2hex(random_bytes(4));

$hm = new Client('hm_sk_test', $base);
$out = $hm->request('POST', "/seq/{$run}a/503,429,200", ['body' => ['a' => 1]]);
check(count($out['seen']) === 3, 'retries 503 and 429 until 200');
check(count(array_unique($out['seen'])) === 1, 'every attempt has the same Idempotency-Key and body');
check(strlen(explode(' ', $out['seen'][0])[0]) === 32, 'a random Idempotency-Key is sent');

try {
    (new Client('hm_sk_test', $base, null, 30, 1))->request('GET', "/seq/{$run}b/502,502,200");
    check(false, 'gives up after maxRetries');
} catch (HermeticaException $e) {
    check($e->status === 502 && count($e->details['seen']) === 2, 'gives up after maxRetries');
}

try {
    $hm->request('GET', "/seq/{$run}c/404,200");
    check(false, 'does not retry 4xx');
} catch (HermeticaException $e) {
    check($e->status === 404 && $e->errorCode === 'e404' && count($e->details['seen']) === 1, 'does not retry 4xx');
}

try {
    (new Client('hm_sk_test', 'http://127.0.0.1:1', null, 5, 1))->request('GET', '/x');
    check(false, 'network errors are retried, then thrown');
} catch (RuntimeException $e) {
    check(!($e instanceof HermeticaException), 'network errors are retried, then thrown');
}

check(Client::retryAfterMs('2') === 2000 && Client::retryAfterMs('-1') === 0 && Client::retryAfterMs('soon') === null, 'Retry-After seconds');
check(Client::retryAfterMs('Thu, 01 Jan 2026 00:00:05 GMT', strtotime('2026-01-01T00:00:00Z')) === 5000, 'Retry-After date');
