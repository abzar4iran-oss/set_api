<?php
/** Quick smoke test for GET /lessons */
$base = getenv('API_BASE') ?: 'http://localhost/api/public';

function getJson(string $url): array {
    $raw = @file_get_contents($url);
    if ($raw === false) {
        throw new RuntimeException("Request failed: $url");
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON from $url");
    }
    return $decoded;
}

$list = getJson($base . '/lessons');
if (empty($list['ok'])) {
    throw new RuntimeException('lessons list not ok: ' . ($list['message'] ?? ''));
}

$data = $list['data'] ?? [];
$count = is_array($data['lessons'] ?? null) ? count($data['lessons']) : 0;
echo "OK list lessons=$count version=" . ($data['version'] ?? '?') . " media=" . ($data['media_base_url'] ?? '') . PHP_EOL;

$one = getJson($base . '/lessons/get?id=1');
if (empty($one['ok'])) {
    throw new RuntimeException('lesson get not ok');
}
$title = $one['data']['lesson']['title'] ?? '';
echo "OK get id=1 title=$title" . PHP_EOL;
