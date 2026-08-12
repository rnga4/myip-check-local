<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

// Terima data info komputer dari JavaScript (fetch POST)
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['visit_id'])) {
    http_response_code(400);
    exit('bad request');
}

$vid = preg_replace('/[^a-f0-9]/', '', $payload['visit_id']);

$allowed = ['screen', 'lang', 'tz', 'cores', 'memory', 'platform', 'touch'];
$lines = is_readable(DATA_FILE)
    ? file(DATA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];

$found = false;
$geo = null;
$out = [];
foreach ($lines as $line) {
    $entry = json_decode($line, true);
    if (is_array($entry) && ($entry['visit_id'] ?? null) === $vid) {
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                $entry[$key] = is_string($payload[$key]) ? mb_substr($payload[$key], 0, 255) : $payload[$key];
            }
        }
        // GeoIP: cuma untuk IP publik, dan cuma kalau belum pernah diisi
        if (!array_key_exists('geo', $entry) && is_public_ip($entry['ip'] ?? '')) {
            $geo = fetch_geo($entry['ip']);
            if ($geo !== null) {
                $entry['geo'] = $geo;
            }
        }
        $entry['tracked_at'] = date('Y-m-d H:i:s');
        $found = true;
    }
    $out[] = json_encode($entry);
}

if ($found) {
    file_put_contents(DATA_FILE, implode(PHP_EOL, $out) . PHP_EOL, LOCK_EX);
    maybe_rotate();
}

header('Content-Type: application/json');
echo json_encode([
    'ok'  => true,
    'geo' => $geo === null ? null : implode(', ', array_filter($geo)),
]);
