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

// Cek apakah IP berada dalam satu CIDR (mis. 192.168.0.0/16).
function ip_in_cidr(string $ip, string $cidr): bool {
    [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ip_long = ip2long($ip);
    if ($bits === null) {
        return $ip_long !== false && $ip_long === ip2long($subnet);
    }
    $subnet_long = ip2long($subnet);
    $bits = (int) $bits;
    if ($ip_long === false || $subnet_long === false || $bits < 0 || $bits > 32) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
    return ($ip_long & $mask) === ($subnet_long & $mask);
}

function is_trusted_proxy(string $ip): bool {
    foreach (trusted_proxies() as $entry) {
        if ($entry === '*') {
            return true;
        }
        if (str_contains($entry, '/')) {
            if (ip_in_cidr($ip, $entry)) {
                return true;
            }
        } elseif ($ip === $entry) {
            return true;
        }
    }
    return false;
}

const ADMIN_USER = 'admin';
const ADMIN_PASSWORD_ENV = 'ADMIN_PASSWORD'; // env, fallback 'ipcheck'

const LOG_FILE = __DIR__ . '/visitor_log.txt';
const DATA_FILE = __DIR__ . '/visitor_data.jsonl';
// Detail komputer & GeoIP disimpan terpisah (append-only), supaya track.php
// tidak perlu menulis ulang seluruh data utama tiap kunjungan.
const DETAILS_FILE = __DIR__ . '/visitor_details.jsonl';
// Cache hitungan kunjungan per IP (dipakai untuk is_new di build_visit_data).
const IP_SEEN_FILE = __DIR__ . '/visitor_ip_seen.json';

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
    $is_trusted = is_trusted_proxy($remote);
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
    // Gabungkan detail komputer & GeoIP (append-only, yang terakhir menang).
    if (is_readable(DETAILS_FILE)) {
        foreach (file(DETAILS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $detail = json_decode($line, true);
            $vid = $detail['visit_id'] ?? null;
            if (is_array($detail) && $vid && isset($rows[$vid])) {
                $rows[$vid] = array_merge($rows[$vid], $detail);
            }
        }
    }
    return $rows;
}

function ip_seen(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = is_readable(IP_SEEN_FILE)
            ? (json_decode((string) file_get_contents(IP_SEEN_FILE), true) ?: [])
            : [];
    }
    return $cache;
}

function ip_visit_count(string $ip): int {
    if ($ip === 'UNKNOWN') {
        return 0;
    }
    return (int) (ip_seen()[$ip] ?? 0);
}

function ip_seen_bump(string $ip): void {
    if ($ip === 'UNKNOWN') {
        return;
    }
    $seen = ip_seen();
    $seen[$ip] = ($seen[$ip] ?? 0) + 1;
    file_put_contents(IP_SEEN_FILE, json_encode($seen), LOCK_EX);
}

function ip_seen_rebuild(): void {
    $counts = [];
    if (is_readable(DATA_FILE)) {
        foreach (file(DATA_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry) && !empty($entry['ip'])) {
                $counts[$entry['ip']] = ($counts[$entry['ip']] ?? 0) + 1;
            }
        }
    }
    file_put_contents(IP_SEEN_FILE, json_encode($counts), LOCK_EX);
}

// Baca beberapa baris terakhir file besar tanpa membaca seluruh isinya.
function tail_lines(string $file, int $count = 100): array {
    if (!is_readable($file)) {
        return [];
    }
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        return [];
    }
    $size = filesize($file);
    $buf = '';
    $pos = $size;
    while ($pos > 0) {
        $len = min(4096, $pos);
        $pos -= $len;
        fseek($fh, $pos);
        $buf = fread($fh, $len) . $buf;
        if (substr_count($buf, "\n") >= $count) {
            break;
        }
    }
    fclose($fh);
    $lines = preg_split('/\R/', trim($buf));
    return array_slice($lines, -$count);
}

function log_visitor(array $data): void {
    $line = "[{$data['time']}] IP: {$data['ip']} | UA: {$data['ua']}" . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    file_put_contents(DATA_FILE, json_encode($data) . PHP_EOL, FILE_APPEND | LOCK_EX);
    ip_seen_bump($data['ip']);
    maybe_rotate();
}

function maybe_rotate(): void {
    $rotated = false;
    foreach ([DATA_FILE, DETAILS_FILE] as $file) {
        if (!file_exists($file) || filesize($file) < LOG_MAX_BYTES) {
            continue;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) > LOG_KEEP_LINES) {
            $keep = array_slice($lines, -LOG_KEEP_LINES);
            file_put_contents($file, implode(PHP_EOL, $keep) . PHP_EOL, LOCK_EX);
            $rotated = true;
        }
    }
    if (is_readable(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_BYTES) {
        $txt_lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($txt_lines) > LOG_KEEP_LINES) {
            $keep = array_slice($txt_lines, -LOG_KEEP_LINES);
            file_put_contents(LOG_FILE, implode(PHP_EOL, $keep) . PHP_EOL, LOCK_EX);
        }
    }
    if ($rotated) {
        ip_seen_rebuild();
    }
}
