<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

function parse_data(): array {
    $rows = load_data();
    $seen = [];
    foreach ($rows as $entry) {
        $seen[$entry['time'] . '|' . $entry['ip']] = true;
    }
    // Fallback: baris log txt yang belum punya detail (JS mati / versi lama)
    if (is_readable(LOG_FILE)) {
        foreach (file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\[([^\]]+)\]\s*IP:\s*([^|]+?)\s*\|\s*UA:\s*(.*)$/', $line, $m)) {
                $key = $m[1] . '|' . $m[2];
                if (!isset($seen[$key])) {
                    $rows[$key] = [
                        'time'     => $m[1],
                        'ip'       => $m[2],
                        'ua'       => $m[3],
                        'hostname' => '-',
                        'os'       => 'Unknown',
                        'browser'  => 'Unknown',
                        'device'   => 'Unknown',
                        'screen'   => '-',
                        'lang'     => '-',
                        'tz'       => '-',
                        'cores'    => '-',
                        'memory'   => '-',
                        'platform' => '-',
                        'is_new'   => false,
                    ];
                }
            }
        }
    }
    // Urutkan dari yang paling baru ke paling lama. Pakai tracked_at
    // (kapan baris benar-benar tercatat) kalau ada, fallback ke time.
    // Reverse dulu urutan natural lalu stable-sort supaya yang sama waktu
    // tetap menaruh yang lebih baru di atas (uasort stabil sejak PHP 8.0).
    $rows = array_reverse($rows, true);
    uasort($rows, function ($a, $b) {
        $ka = $a['tracked_at'] ?? $a['time'] ?? '';
        $kb = $b['tracked_at'] ?? $b['time'] ?? '';
        $ta = strtotime((string) $ka);
        $tb = strtotime((string) $kb);
        if ($ta === false) $ta = 0;
        if ($tb === false) $tb = 0;
        return $tb <=> $ta;
    });
    return $rows;
}

function rel_time(string $time): string {
    $ts = strtotime($time);
    if ($ts === false) {
        return '';
    }
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'baru saja';
    if ($diff < 3600) return intdiv($diff, 60) . ' menit lalu';
    if ($diff < 86400) return intdiv($diff, 3600) . ' jam lalu';
    return intdiv($diff, 86400) . ' hari lalu';
}

if (!is_admin()) {
    header('WWW-Authenticate: Basic realm="IP Check Admin"');
    header('HTTP/1.1 401 Unauthorized');
    exit('Akses ditolak. Masukkan kredensial admin.');
}

// Hapus semua log (POST aja, biar nggak ke-trigger lewat URL langsung)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    file_put_contents(DATA_FILE, '', LOCK_EX);
    file_put_contents(LOG_FILE, '', LOCK_EX);
    header('Location: admin.php');
    exit;
}

$all_visitors = parse_data();

// Hitung jumlah kunjungan per IP dari SEMUA data (tidak terpengaruh filter)
$ip_counts = [];
foreach ($all_visitors as $v) {
    $ip_counts[$v['ip']] = ($ip_counts[$v['ip']] ?? 0) + 1;
}

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $needle = strtolower($q);
    $all_visitors = array_filter($all_visitors, function ($v) use ($needle) {
        foreach (['ip', 'hostname', 'os', 'browser', 'device', 'ua', 'time'] as $field) {
            if (stripos((string) ($v[$field] ?? ''), $needle) !== false) {
                return true;
            }
        }
        return false;
    });
}

// Filter rentang waktu: all / today / 7 / 30
$range = (string) ($_GET['range'] ?? 'all');
if (!in_array($range, ['all', 'today', '7', '30'], true)) {
    $range = 'all';
}
if ($range !== 'all') {
    $cutoff = $range === 'today'
        ? strtotime('today')
        : strtotime('-' . $range . ' days');
    $all_visitors = array_filter($all_visitors, function ($v) use ($cutoff) {
        $t = strtotime((string) ($v['time'] ?? ''));
        return $t !== false && $t >= $cutoff;
    });
}

$total_all = count($all_visitors);

$per_page = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pages = (int) ceil($total_all / $per_page);
$page = min($page, max(1, $pages));
$offset = ($page - 1) * $per_page;
$visitors = array_slice($all_visitors, $offset, $per_page, true);

$total_screen = 0;
foreach ($all_visitors as $v) {
    if (($v['screen'] ?? '-') !== '-') $total_screen++;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Panel - Cek IP</title>
<link rel="stylesheet" href="pl-komatsu-ui-template.css">
<script src="theme.js"></script>
<style>
  .wrap { max-width: 1150px; width: 100%; margin-top: 6vh; }
  .page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 28px; }
  h1 { font-family: var(--font-display); font-size: clamp(1.7rem, 4vw, 2.4rem); font-weight: 600; margin: 0 0 4px; }
  .subtitle { color: var(--muted-foreground); font-size: 0.95rem; margin: 0; }
  .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 26px; }
  .stat { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 18px 22px; box-shadow: var(--shadow-sm); }
  .stat-val { font-family: var(--font-mono); font-size: 1.9rem; font-weight: 700; color: var(--primary); line-height: 1.1; }
  .stat-lbl { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted-foreground); margin-top: 6px; }
  .toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 26px; }
  .toolbar form { margin: 0; display: flex; align-items: center; gap: 10px; }
  .btn-danger { background: var(--destructive); color: #fff; }
  .btn-danger:hover { background: var(--destructive); }
  .search-info { color: var(--muted-foreground); font-size: 0.85rem; margin: -14px 0 20px; }
  .sub-section-label { margin: 30px 0 12px; }
  .scroll-wrap { overflow-x: auto; }
  /* perbaikan quirk template: jangan bold baris terakhir, header biasa tanpa sticky/shadow */
  .price-table th { position: static; box-shadow: none; }
  .price-table tr:last-child td { border-bottom: 1px solid var(--border); font-weight: normal; }
  .price-table td { white-space: nowrap; }
  .price-table .wrap-cell { white-space: normal; min-width: 320px; }
  .price-table .muted-cell { color: var(--muted-foreground); }
  .tag { display: inline-block; margin: 2px 6px 2px 0; padding: 3px 10px; border: 1px solid var(--border); border-radius: 99px; font-size: 0.75rem; color: var(--muted-foreground); background: var(--muted); }
  .tag b { color: var(--foreground); }
  .visit-badge { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 99px; font-size: 0.62rem; font-weight: 700; letter-spacing: 0.05em; }
  .visit-badge.new { background: oklch(0.88 0.1 145); color: oklch(0.32 0.1 150); }
  .visit-badge.back { background: var(--muted); color: var(--muted-foreground); }
  .pagination { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 18px; }
  .pagination a { padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px; text-decoration: none; color: var(--foreground); background: var(--card); }
  .pagination a.cur { background: var(--primary); color: var(--primary-foreground); border-color: var(--primary); }
  .price-table td, .price-table th, .stat, h1, .subtitle { user-select: text; -webkit-user-select: text; }
  .range-filter { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .range-lbl { font-size: 0.78rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase; color: var(--muted-foreground); }
  .range-filter .btn-sm.active { background: var(--primary); color: var(--primary-foreground); border-color: var(--primary); }
  @media (max-width: 768px) {
    body { justify-content: flex-start; padding-top: 56px; }
    .container-input { width: 100%; }
    .container-input .input { width: 100%; }
    .container-input .input:focus { width: 100%; }
    .toolbar { gap: 10px; }
    .toolbar form { width: 100%; flex-wrap: wrap; }
    .toolbar form .btn-sm { flex: 1; white-space: nowrap; }
    .range-filter { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; }
    .range-filter .btn-sm { flex: 0 0 auto; }
    .page-head { align-items: flex-start; flex-direction: column; }
    .theme-popup { top: 10px; right: 10px; }
    .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .stat { padding: 14px 16px; }
    .stat-val { font-size: 1.5rem; }
    .stat-lbl { font-size: 0.62rem; }
    .scroll-wrap { margin: 0 -2px; }
    .wrap { margin-top: 2vh; }
  }
  @media (max-width: 480px) {
    body { padding: 52px 10px 24px; }
    .stat-grid { gap: 8px; }
    .stat { padding: 12px 12px; }
    .stat-val { font-size: 1.3rem; }
    .toolbar form .btn-sm { font-size: 0.74rem; padding: 6px 10px; }
    .price-table td, .price-table th { padding: 7px 8px; }
  }
</style>
</head>
<body>
  <?php include __DIR__ . '/theme-popup.php'; ?>
  <div class="wrap">
    <header class="page-head">
      <div>
        <span class="badge">IP Check</span>
        <h1>Admin Panel</h1>
        <p class="subtitle">Daftar kunjungan dan identitas visitor</p>
      </div>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <a class="btn-sm" href="/">&larr; Halaman utama</a>
      </div>
    </header>

    <div class="stat-grid">
      <div class="stat"><div class="stat-val"><?= $total_all ?></div><div class="stat-lbl">Total kunjungan</div></div>
      <div class="stat"><div class="stat-val"><?= count($ip_counts) ?></div><div class="stat-lbl">IP unik</div></div>
      <div class="stat"><div class="stat-val"><?= $total_screen ?></div><div class="stat-lbl">Detail komputer</div></div>
    </div>

    <div class="toolbar">
      <form method="get" action="admin.php">
        <div class="container-input">
          <input type="text" class="input" name="q" placeholder="Cari IP, OS, browser..." value="<?= htmlspecialchars($q) ?>">
        </div>
        <button type="submit" class="btn-sm">Cari</button>
        <?php if ($q !== ''): ?><a class="btn-sm" href="admin.php">Reset</a><?php endif; ?>
      </form>
      <form method="post" action="admin.php" onsubmit="return confirm('Yakin mau hapus semua data visitor?')">
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="btn-sm btn-danger">Hapus Semua Data</button>
      </form>
      <div class="range-filter">
        <span class="range-lbl">Periode:</span>
        <?php
          $qs = $q !== '' ? 'q=' . urlencode($q) . '&' : '';
          $ranges = ['today' => 'Hari ini', '7' => '7 hari', '30' => '30 hari', 'all' => 'Semua'];
          foreach ($ranges as $val => $lbl):
            $cls = $range === (string) $val ? ' active' : '';
        ?>
          <a class="btn-sm<?= $cls ?>" href="admin.php?<?= $qs ?>range=<?= $val ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($q !== ''): ?>
      <p class="search-info">Menampilkan <b><?= $total_all ?></b> hasil untuk "<b><?= htmlspecialchars($q) ?></b>"</p>
    <?php endif; ?>

    <h2 class="sub-section-label">Top IP</h2>
    <div class="card">
      <?php arsort($ip_counts); ?>
      <div class="scroll-wrap">
      <table class="price-table">
        <tr><th>IP</th><th>Jumlah</th></tr>
        <?php foreach (array_slice($ip_counts, 0, 10, true) as $ip => $count): ?>
          <tr><td class="td-pn"><?= htmlspecialchars($ip) ?></td><td><?= $count ?></td></tr>
        <?php endforeach; ?>
        <?php if (empty($ip_counts)): ?><tr><td colspan="2">Belum ada data.</td></tr><?php endif; ?>
      </table>
      </div>
    </div>

    <h2 class="sub-section-label">Daftar Visitor</h2>
    <div class="card">
      <div class="scroll-wrap">
      <table class="price-table">
        <tr>
          <th>IP</th><th>#</th><th>Waktu</th><th>Hostname</th><th>Lokasi</th><th>Detail</th>
        </tr>
        <?php $n = 0; ?>
        <?php foreach ($visitors as $v): ?>
          <?php
            $n++;
            $v = array_merge([
                'screen' => '-', 'lang' => '-', 'tz' => '-', 'cores' => '-',
                'memory' => '-', 'platform' => '-', 'touch' => '-',
            ], $v);
          ?>
          <?php
            $v['geo_text'] = '';
            if (!empty($v['geo']) && is_array($v['geo'])) {
                $v['geo_text'] = implode(', ', array_filter($v['geo']));
            }
          ?>
          <?php foreach (['time','ip','hostname','os','browser','device','screen','lang','tz','cores','memory','platform','touch','ua','geo_text'] as $field): ?>
            <?php $v[$field] = is_scalar($v[$field] ?? null) ? (string) $v[$field] : ($v[$field] ?? '-'); ?>
          <?php endforeach; ?>
          <?php
            $is_new = !empty($v['is_new']);
            $ip_total = $ip_counts[$v['ip']] ?? 1;
          ?>
          <tr>
            <td>
              <span class="td-pn"><?= htmlspecialchars($v['ip']) ?></span>
              <span class="count-badge"><?= $ip_total ?>x</span>
              <?php if ($is_new): ?>
                <span class="visit-badge new">BARU</span>
              <?php else: ?>
                <span class="visit-badge back">KEMBALI</span>
              <?php endif; ?>
            </td>
            <td class="muted-cell"><?= $n ?></td>
            <td>
              <?= htmlspecialchars($v['time']) ?>
              <span class="muted-cell" style="display:block;font-size:0.78rem;"><?= rel_time($v['time']) ?></span>
            </td>
            <td><?= htmlspecialchars($v['hostname']) ?></td>
            <td><?= htmlspecialchars($v['geo_text'] ?: '-') ?></td>
            <td class="wrap-cell" title="<?= htmlspecialchars($v['ua']) ?>">
              <?php if (($v['screen'] ?? '-') !== '-'): ?>
                <span><b><?= htmlspecialchars($v['os']) ?></b> · <b><?= htmlspecialchars($v['browser']) ?></b> · <b><?= htmlspecialchars($v['device']) ?></b></span>
                <?php
                  $details = [];
                  $specs = [
                      'screen'   => ['Layar ', ''],
                      'lang'     => ['', ''],
                      'tz'       => ['TZ ', ''],
                      'cores'    => ['', ' core'],
                      'memory'   => ['RAM ', ''],
                      'platform' => ['', ''],
                      'touch'    => ['Touch ', ''],
                  ];
                  foreach ($specs as $k => [$pre, $suf]) {
                      $val = $v[$k];
                      if ($val === '-' || $val === '' || $val === null) continue;
                      $details[] = $pre . $val . $suf;
                  }
                ?>
                <?php if ($details): ?>
                  <div class="muted-cell" style="margin-top:4px;"><?= htmlspecialchars(implode(' · ', $details)) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <span><b><?= htmlspecialchars($v['os']) ?></b> · <b><?= htmlspecialchars($v['browser']) ?></b> · <b><?= htmlspecialchars($v['device']) ?></b></span>
                <div class="muted-cell" style="margin-top:4px;">(detail komputer tidak ada)</div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($visitors)): ?>
          <tr><td colspan="6">Belum ada visitor tercatat.</td></tr>
        <?php endif; ?>
      </table>
      </div>
      <?php if ($pages > 1): ?>
        <div class="pagination">
          <?php
            $qs = 'q=' . urlencode($q) . '&';
            for ($p = 1; $p <= $pages; $p++) {
                $cls = $p === $page ? ' class="cur"' : '';
                echo '<a' . $cls . ' href="admin.php?' . $qs . 'page=' . $p . '">' . $p . '</a>';
            }
          ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
