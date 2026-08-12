<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

// API JSON: info lengkap visitor saat ini (bukan semua visitor).
// Contoh: curl http://host/api.php
$data = build_visit_data();
log_visitor($data);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
