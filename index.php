<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$data = build_visit_data();
log_visitor($data);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cek IP</title>
<link rel="stylesheet" href="pl-komatsu-ui-template.css">
<script src="theme.js"></script>
<style>
  .hero { margin-bottom: 24px; }
  .card { max-width: 460px; width: 100%; text-align: center; }
  .card-pn { font-size: clamp(1.8rem, 9vw, 2.2rem); letter-spacing: -0.01em; overflow-wrap: anywhere; }
  #copy-btn { margin-top: 4px; }
  @media (max-width: 768px) {
    body { justify-content: center; min-height: 100svh; }
    .hero { margin-bottom: 22px; }
    .card { padding: 22px 18px; }
    #copy-btn { padding: 16px 25px; }
  }
</style>
</head>
<body>
  <?php include __DIR__ . '/theme-popup.php'; ?>
  <div class="hero">
    <span class="badge" id="secret-badge" style="cursor:default">IP Address</span>
  </div>
  <div class="card">
    <div class="card-label">Alamat IP kamu</div>
    <div class="card-pn" id="ip"><?= htmlspecialchars($data['ip']) ?></div>
    <button class="nametag-btn" id="copy-btn" onclick="copyIp()">Salin IP</button>
  </div>
  <script>
    function copyIp() {
      const ip = document.getElementById('ip').textContent.trim();
      const btn = document.getElementById('copy-btn');
      function done() {
        btn.textContent = 'Tersalin!';
        setTimeout(function () { btn.textContent = 'Salin IP'; }, 1500);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ip).then(done, function () { fallbackCopy(ip, done); });
      } else {
        fallbackCopy(ip, done);
      }
    }
    function fallbackCopy(text, done) {
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      document.body.removeChild(ta);
    }
    (function () {
      let clicks = 0, timer = null;
      const badge = document.getElementById('secret-badge');
      function goAdmin() {
        clicks = 0;
        window.location.href = 'admin.php';
      }
      badge.addEventListener('click', function () {
        clicks++;
        clearTimeout(timer);
        timer = setTimeout(function () { clicks = 0; }, 2000);
        if (clicks >= 5) goAdmin();
      });
      document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.shiftKey && (e.key === 'A' || e.key === 'a')) {
          e.preventDefault();
          goAdmin();
        }
      });
    })();
    (function () {
      const visitId = <?= json_encode($data['visit_id']) ?>;
      const info = {
        screen: (screen && screen.width && screen.height) ? screen.width + 'x' + screen.height : null,
        lang: navigator.language || null,
        tz: (Intl.DateTimeFormat().resolvedOptions().timeZone) || null,
        cores: navigator.hardwareConcurrency || null,
        memory: navigator.deviceMemory ? navigator.deviceMemory + ' GB' : null,
        platform: navigator.platform || null,
        touch: ('ontouchstart' in window) ? 'Ya' : 'Tidak'
      };
      fetch('track.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ visit_id: visitId }, info))
      }).catch(function () {});
    })();
  </script>
</body>
</html>
