<?php
declare(strict_types=1);

/* =====================================================================
   Timezone default: Asia/Jakarta. Bisa diganti lewat env APP_TIMEZONE
   (contoh: Asia/Singapore, America/New_York). php:8.2-cli-alpine bawaan
   container default-nya UTC, makanya wajib di-set biar jamnya akurat.
   ===================================================================== */
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

/* =====================================================================
   Konfigurasi & fungsi bersama (dipakai index.php, admin.php,
   track.php, api.php)
   ===================================================================== */

// Set true HANYA kalau akses lewat reverse proxy tepercaya (nginx/caddy).
// Bisa di-set lewat env TRUST_PROXY=true di docker-compose.
define('TRUST_PROXY', filter_var(getenv('TRUST_PROXY'), FILTER_VALIDATE_BOOLEAN));

// Daftar IP proxy tepercaya (dipisah koma) via env TRUSTED_PROXIES.
// Lebih aman dari TRUST_PROXY=true: header X-Forwarded-For / X-Real-IP hanya
// dihormati kalau request benar-benar datang dari IP proxy tersebut.
function trusted_proxies(): array {
    $val = getenv('TRUSTED_PROXIES');
    if ($val !== false && trim($val) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $val))));
    }
    return TRUST_PROXY ? ['*'] : [];
}

const ADMIN_USER = 'admin';
const ADMIN_PASSWORD_ENV = 'ADMIN_PASSWORD'; // env, fallback 'ipcheck'

const LOG_FILE = __DIR__ . '/visitor_log.txt';
const DATA_FILE = __DIR__ . '/visitor_data.jsonl';

// Rotasi otomatis: kalau visitor_data.jsonl melebihi batas (byte),
// data dipotong menyisakan LOG_KEEP_LINES baris terakhir.
const LOG_MAX_BYTES = 10 * 1024 * 1024; // 10 MB
const LOG_KEEP_LINES = 2000;

const GEO_TIMEOUT = 3; // detik untuk request ip-api.com

/* ------------------------------ auth ------------------------------- */

function admin_password(): string {
    $p = getenv(ADMIN_PASSWORD_ENV);
    return $p !== false && $p !== '' ? $p : 'ipcheck';
}

function get_basic_auth(): array {
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']];
    }
    // PHP built-in server nggak mengisi PHP_AUTH_USER, parse header manual
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (stripos($header, 'basic ') === 0) {
        $decoded = base64_decode(substr($header, 6));
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            [$user, $pass] = explode(':', $decoded, 2);
            return [$user, $pass];
        }
    }
    return [null, null];
}

function is_admin(): bool {
    [$user, $pass] = get_basic_auth();
    return $user === ADMIN_USER
        && is_string($pass)
        && hash_equals(admin_password(), $pass);
}

/* ------------------------- identitas visitor ------------------------ */

function get_visitor_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $trusted = trusted_proxies();
    $is_trusted = in_array('*', $trusted, true) || in_array($remote, $trusted, true);
    if ($is_trusted) {
        // X-Forwarded-For: IP client asli ditambahkan oleh proxy paling luar
        // (mis. NPM), lalu proxy dalam (nginx web) menambahkan IP-nya sendiri
        // ke kanan. Karena itu ambil IP valid paling kiri.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
                $ip = trim($part);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        // X-Real-IP: dipakai cuma kalau X-Forwarded-For tidak ada. Catatan:
        // nginx web meng-overwrite header ini dengan $remote_addr-nya sendiri,
        // jadi kalau diprioritaskan hasilnya bisa jadi IP proxy (host), bukan client.
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'UNKNOWN';
}

function get_hostname(string $ip): string {
    if ($ip === 'UNKNOWN' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return 'UNKNOWN';
    }
    $name = gethostbyaddr($ip);
    // gethostbyaddr mengembalikan IP yang sama kalau nggak ada PTR record
    return ($name === $ip) ? '-' : $name;
}

function parse_user_agent(string $ua): array {
    $ua = strtolower($ua);
    $os = 'Unknown';
    $os_patterns = [
        'windows nt 10.0' => 'Windows 10/11',
        'windows nt 6.3'    => 'Windows 8.1',
        'windows nt 6.2'    => 'Windows 8',
        'windows nt 6.1'    => 'Windows 7',
        'windows'           => 'Windows',
        'android'           => 'Android',
        'iphone'            => 'iOS (iPhone)',
        'ipad'              => 'iOS (iPad)',
        'mac os x'          => 'macOS',
        'linux'             => 'Linux',
    ];
    foreach ($os_patterns as $needle => $label) {
        if (str_contains($ua, $needle)) {
            $os = $label;
            break;
        }
    }

    $browser = 'Unknown';
    if (str_contains($ua, 'edg/')) {
        $browser = 'Edge';
    } elseif (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) {
        $browser = 'Opera';
    } elseif (str_contains($ua, 'chromium')) {
        $browser = 'Chromium';
    } elseif (str_contains($ua, 'chrome')) {
        $browser = 'Chrome';
    } elseif (str_contains($ua, 'firefox')) {
        $browser = 'Firefox';
    } elseif (str_contains($ua, 'safari')) {
        $browser = 'Safari';
    }

    $device = 'Desktop';
    if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
        $device = 'Tablet';
    } elseif (str_contains($ua, 'mobile')) {
        $device = 'Mobile';
    }

    return ['os' => $os, 'browser' => $browser, 'device' => $device];
}

function build_visit_data(): array {
    $ip = get_visitor_ip();
    $parsed = parse_user_agent($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    return [
        'visit_id' => bin2hex(random_bytes(8)),
        'time'     => date('Y-m-d H:i:s'),
        'ip'       => $ip,
        'hostname' => get_hostname($ip),
        'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'os'       => $parsed['os'],
        'browser'  => $parsed['browser'],
        'device'   => $parsed['device'],
        // is_new = IP ini belum pernah tercatat sebelumnya
        'is_new'   => ip_visit_count($ip) === 0,
    ];
}

function is_public_ip(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function fetch_geo(string $ip): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => GEO_TIMEOUT]]);
    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,city,regionName';
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return null;
    }
    $d = json_decode($json, true);
    if (($d['status'] ?? '') !== 'success') {
        return null;
    }
    return [
        'city'    => $d['city'] ?? '',
        'region'  => $d['regionName'] ?? '',
        'country' => $d['country'] ?? '',
    ];
}

/* ----------------------------- penyimpanan --------------------------- */

function load_data(): array {
    $rows = [];
    if (is_readable(DATA_FILE)) {
        foreach (file(DATA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && !empty($entry['visit_id'])) {
                $rows[$entry['visit_id']] = $entry;
            }
        }
    }
    return $rows;
}

function ip_visit_count(string $ip): int {
    if ($ip === 'UNKNOWN' || !is_readable(DATA_FILE)) {
        return 0;
    }
    $needle = '"ip":"' . addcslashes($ip, '"\\') . '"';
    return substr_count(file_get_contents(DATA_FILE), $needle);
}

function log_visitor(array $data): void {
    $line = "[{$data['time']}] IP: {$data['ip']} | UA: {$data['ua']}" . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    file_put_contents(DATA_FILE, json_encode($data) . PHP_EOL, FILE_APPEND | LOCK_EX);
    maybe_rotate();
}

function maybe_rotate(): void {
    if (!file_exists(DATA_FILE) || filesize(DATA_FILE) < LOG_MAX_BYTES) {
        return;
    }
    $data_lines = file(DATA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($data_lines) > LOG_KEEP_LINES) {
        $keep = array_slice($data_lines, -LOG_KEEP_LINES);
        file_put_contents(DATA_FILE, implode(PHP_EOL, $keep) . PHP_EOL, LOCK_EX);
    }
    if (is_readable(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_BYTES) {
        $txt_lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($txt_lines) > LOG_KEEP_LINES) {
            $keep = array_slice($txt_lines, -LOG_KEEP_LINES);
            file_put_contents(LOG_FILE, implode(PHP_EOL, $keep) . PHP_EOL, LOCK_EX);
        }
    }
}
