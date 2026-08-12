<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

// Terima data info komputer dari JavaScript (fetch POST).
// Detail disimpan append-only ke visitor_details.jsonl (tidak menulis ulang
// seluruh data utama), lalu digabung saat admin membaca data.
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || empty($payload['visit_id'])) {
    http_response_code(400);
    exit('bad request');
}

$vid = preg_replace('/[^a-f0-9]/', '', $payload['visit_id']);
if ($vid === '') {
    http_response_code(400);
    exit('bad request');
}

$detail = ['visit_id' => $vid];
$allowed = ['screen', 'lang', 'tz', 'cores', 'memory', 'platform', 'touch'];
foreach ($allowed as $key) {
    if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
        $detail[$key] = is_string($payload[$key]) ? mb_substr($payload[$key], 0, 255) : $payload[$key];
    }
}

// Jangan dobel: kalau detail visit_id ini sudah ada, langsung selesai.
$has_detail = false;
foreach (array_reverse(tail_lines(DETAILS_FILE, 20)) as $line) {
    $d = json_decode($line, true);
    if (is_array($d) && ($d['visit_id'] ?? null) === $vid) {
        $has_detail = true;
        break;
    }
}
if ($has_detail) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// GeoIP: perlu IP visitor. Baris kunjungan ini hampir pasti ada di ujung
// data utama (baru saja dicatat index.php), jadi cukup baca ekornya saja.
$geo = null;
$base = null;
foreach (array_reverse(tail_lines(DATA_FILE, 50)) as $line) {
    $entry = json_decode($line, true);
    if (is_array($entry) && ($entry['visit_id'] ?? null) === $vid) {
        $base = $entry;
        break;
    }
}
if ($base !== null && !empty($base['ip']) && is_public_ip($base['ip'])) {
    $geo = fetch_geo($base['ip']);
}

if ($geo !== null) {
    $detail['geo'] = $geo;
}
$detail['tracked_at'] = date('Y-m-d H:i:s');
file_put_contents(DETAILS_FILE, json_encode($detail) . PHP_EOL, FILE_APPEND | LOCK_EX);
maybe_rotate();

header('Content-Type: application/json');
echo json_encode([
    'ok'  => true,
    'geo' => $geo === null ? null : implode(', ', array_filter($geo)),
]);
