# Cek IP (IP Check)

Pencatat kunjungan + identitas visitor. Plain PHP 8.2 tanpa framework, jalan di Docker (php-fpm + nginx). Bahasa UI: Indonesia.

## Fitur

- Menampilkan IP pengunjung dengan tombol salin.
- Mencatat kunjungan otomatis: waktu, IP, hostname, OS, browser, device (dari User-Agent) + detail komputer via JavaScript (resolusi layar, bahasa, zona waktu, jumlah core, RAM, platform, touch).
- GeoIP otomatis untuk IP publik via [ip-api.com](http://ip-api.com) (satu kali per kunjungan, timeout 3 detik).
- Admin panel: statistik, grafik kunjungan per hari, tabel visitor, top IP, pencarian, filter periode, pagination, export CSV/JSON, hapus semua data.
- Theme switcher (8 tema) dari PL-Komatsu UI Template.
- JSON API untuk info visitor saat ini: `curl http://host/api.php`.
- Rotasi data otomatis (maks 10 MB, sisakan 2000 baris terakhir).

## Cara menjalankan

```bash
cp .env.example .env        # sesuaikan ADMIN_PASSWORD & TRUSTED_PROXIES
docker compose up -d
curl http://localhost:8090/ # tes
```

## Konfigurasi (.env)

| Variabel | Default | Keterangan |
|---|---|---|
| `ADMIN_PASSWORD` | `ipcheck` | Password admin panel. **Wajib diganti** untuk penggunaan publik. |
| `TRUST_PROXY` | `true` | Hormati header proxy kalau `TRUSTED_PROXIES` kosong (percaya semua proxy). |
| `TRUSTED_PROXIES` | kosong | IP/CIDR proxy tepercaya (dipisah koma), mis. `192.168.0.0/16,100.64.0.0/10`. Header `X-Forwarded-For` hanya dihormati dari IP ini — lebih aman dari `TRUST_PROXY=true`. |
| `APP_TIMEZONE` | `Asia/Jakarta` | Zona waktu pencatatan. |

> Rekomendasi keamanan: ganti `ADMIN_PASSWORD`, dan isi `TRUSTED_PROXIES` dengan IP proxy/NPM kamu (bukan `*`). Untuk akses publik, letakkan di belakang reverse proxy ber-TLS (mis. Nginx Proxy Manager) dan manfaatkan Access List / Fail2Ban untuk melindungi admin panel.

## Akses admin (rahasia)

Admin panel sengaja tidak di-link terlihat. Dari halaman utama:

- **Klik badge "IP Address" 5x dalam 2 detik**, atau
- **Ctrl+Shift+A**

Lalu masuk dengan HTTP Basic Auth: user `admin`, password dari `ADMIN_PASSWORD`.

## Arsitektur

```
NPM / proxy lain
      │ X-Forwarded-For: <ip client>, <ip proxy>
      ▼
ipcheck-web  (nginx, port 8090)
      │ FastCGI
      ▼
ipcheck      (php-fpm)
      │
      ├─ visitor_data.jsonl    data utama kunjungan (JSONL)
      ├─ visitor_details.jsonl detail komputer + GeoIP (append-only)
      ├─ visitor_ip_seen.json  cache hitungan per IP
      └─ visitor_log.txt       log teks fallback
```

- **Semua file PHP di-mount read-only ke kedua kontainer** (`app` & `web`). Edit file langsung berlaku tanpa rebuild.
- Data disimpan sebagai JSONL: `visitor_data.jsonl` satu baris per kunjungan, `visitor_details.jsonl` satu baris per detail/GeoIP, digabung saat admin membaca (yang terakhir menang).
- Deteksi IP: baca `X-Forwarded-For` ambil IP valid **paling kiri** (ditambahkan proxy terluar); `X-Real-IP` hanya fallback.

## Struktur file

| File | Fungsi |
|---|---|
| `index.php` | Halaman publik + kirim detail komputer via JS |
| `admin.php` | Admin panel (Basic Auth): statistik, grafik, tabel, cari, export, hapus |
| `api.php` | JSON info visitor saat ini (juga mencatat kunjungan) |
| `track.php` | Terima POST detail dari JS + GeoIP, simpan append-only |
| `config.php` | Konstanta & fungsi bersama (auth, deteksi IP, geo, penyimpanan, rotasi) |
| `theme-popup.php` / `theme.js` / `pl-komatsu-ui-template.css` | Theme switcher & CSS |
| `docker-compose.yml` / `Dockerfile` / `nginx.conf` | Infrastruktur |
| `CLAUDE.md` | Catatan arsitektur & gotcha untuk AI/kolaborator |

## Perintah umum

```bash
docker compose up -d app        # apply perubahan mount / recreate
docker compose up -d --build    # rebuild image (jarang perlu)
docker compose ps               # cek status + healthcheck
docker exec ipcheck php -l /app/index.php   # cek syntax PHP di kontainer
curl http://localhost:8090/api.php          # tes API
```

## Lisensi

Bebas dipakai. Data kunjungan adalah milik pemasang masing-masing.
