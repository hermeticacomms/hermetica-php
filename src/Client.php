<?php
declare(strict_types=1);

namespace Hermetica;

final class HermeticaException extends \RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public mixed $details = null)
    {
        parent::__construct("$status $errorCode: $message");
    }
}

/** Needs PHP 8.1+ and the curl extension. `new Client('hm_sk_...', 'https://api.example.com')`. */
final class Client
{
    use Operations;

    /** $maxRetries: how many times a network error, 429 or 5xx is retried after the first attempt; 0 turns retries off. */
    public function __construct(private string $apiKey, private string $baseUrl = 'http://localhost:4000', private ?string $workspaceId = null, private int $timeout = 30, private int $maxRetries = 3)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** @param array<string,mixed> $o */
    public function request(string $method, string $path, array $o = []): mixed
    {
        $path = preg_replace_callback('/\{(\w+)\}/', fn ($m) => rawurlencode((string) ($o['path'][$m[1]] ?? '')), $path);
        $query = array_filter($o['query'] ?? [], fn ($v) => $v !== null);
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'];
        if ($this->workspaceId) $headers[] = 'X-Workspace-Id: ' . $this->workspaceId;
        $body = null;
        if (isset($o['body'])) { $body = json_encode($o['body'], JSON_THROW_ON_ERROR); $headers[] = 'Content-Type: application/json'; }
        if ($method !== 'GET') $headers[] = 'Idempotency-Key: ' . ($o['idempotencyKey'] ?? bin2hex(random_bytes(16))); // a retry with the same key never repeats the action
        // Every call is safe to replay: a GET, or a non-GET carrying the same Idempotency-Key on every attempt.
        for ($attempt = 0; ; $attempt++) {
            $retryAfter = null;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter): int {
                    if (stripos($line, 'retry-after:') === 0) $retryAfter = trim(substr($line, 12));
                    return strlen($line);
                },
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $raw = curl_exec($ch);
            if ($raw === false) {
                if ($attempt >= $this->maxRetries) throw new \RuntimeException(curl_error($ch));
                usleep(self::backoffMs($attempt) * 1000);
                continue;
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) {
                usleep((self::retryAfterMs($retryAfter) ?? self::backoffMs($attempt)) * 1000);
                continue;
            }
            break;
        }
        $out = $raw === '' ? null : json_decode($raw, true);
        if ($status >= 300) {
            $e = is_array($out) ? ($out['error'] ?? []) : [];
            throw new HermeticaException($status, $e['code'] ?? 'http_error', $e['message'] ?? substr($raw, 0, 200), $e['details'] ?? null);
        }
        return $out;
    }

    /** Full jitter: a random wait up to 250 ms * 2^attempt, capped at 8 s. */
    private static function backoffMs(int $attempt): int
    {
        return random_int(0, min(8000, 250 * 2 ** min($attempt, 10)) - 1);
    }

    /** Retry-After as delta-seconds or an HTTP date, in ms; null when absent or unparseable. */
    public static function retryAfterMs(?string $value, ?int $now = null): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (int) max(0, round((float) $value * 1000));
        $at = strtotime($value);
        return $at === false ? null : max(0, ($at - ($now ?? time())) * 1000);
    }

    /** Calls $operation (a method name such as 'getV1Profiles') until nextCursor is empty and yields every item. */
    public function paginate(string $operation, array $o = []): \Generator
    {
        while (true) {
            $page = $this->$operation($o);
            foreach ($page['items'] ?? [] as $item) yield $item;
            if (empty($page['nextCursor'])) return;
            $o['query']['cursor'] = $page['nextCursor'];
        }
    }
}
