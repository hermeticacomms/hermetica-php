<?php
declare(strict_types=1);

[, , $id, $statuses] = explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) + [null, null, 'x', '200'];
$log = sys_get_temp_dir() . '/hermetica-test-' . preg_replace('/\W/', '', $id);
file_put_contents($log, ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '') . ' ' . md5(file_get_contents('php://input')) . "\n", FILE_APPEND);
$seen = file($log, FILE_IGNORE_NEW_LINES);
$list = explode(',', $statuses);
$status = (int) ($list[count($seen) - 1] ?? end($list));
http_response_code($status);
header('Content-Type: application/json');
if ($status >= 300) header('Retry-After: 0');
echo json_encode($status >= 300 ? ['error' => ['code' => 'e' . $status, 'message' => 'failed', 'details' => ['seen' => $seen]]] : ['seen' => $seen]);
