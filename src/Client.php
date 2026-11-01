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

    public function __construct(private string $apiKey, private string $baseUrl = 'http://localhost:4000', private ?string $workspaceId = null, private int $timeout = 30)
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) throw new \RuntimeException(curl_error($ch));
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $out = $raw === '' ? null : json_decode($raw, true);
        if ($status >= 300) {
            $e = is_array($out) ? ($out['error'] ?? []) : [];
            throw new HermeticaException($status, $e['code'] ?? 'http_error', $e['message'] ?? substr($raw, 0, 200), $e['details'] ?? null);
        }
        return $out;
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
