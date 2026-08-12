# Proyek: Cek IP (IP Check)

Pencatat kunjungan + identitas visitor. Plain PHP 8.2 tanpa framework, jalan di Docker (php-fpm + nginx). Bahasa UI: Indonesia.

## Struktur file

| File | Fungsi |
|---|---|
| `index.php` | Halaman publik: menampilkan IP visitor, kirim data komputer via JS ke `track.php` |
| `admin.php` | Admin panel (dilindungi HTTP Basic Auth). Tabel visitor, top IP, cari, filter periode, pagination, hapus semua data |
| `api.php` | JSON info visitor saat ini (curl http://host/api.php), sekaligus mencatat kunjungan |
| `track.php` | Menerima POST JSON dari JS (screen, lang, tz, cores, memory, platform, touch) + GeoIP, perbaiki baris data berdasarkan `visit_id` |
| `config.php` | Semua fungsi & konstanta bersama: auth, deteksi IP, parse UA, geo, penyimpanan, rotasi |
| `theme-popup.php` / `theme.js` / `pl-komatsu-ui-template.css` | Theme switcher & CSS dari PL-Komatsu UI Template |
| `visitor_data.jsonl` | Data utama (JSONL, key = `visit_id`) |
| `visitor_details.jsonl` | Detail komputer + GeoIP (JSONL append-only, digabung saat admin baca) |
| `visitor_ip_seen.json` | Cache hitungan kunjungan per IP (untuk is_new) |
| `visitor_log.txt` | Log teks sederhana (fallback kalau JS mati) |

## Arsitektur Docker (PENTING)

- **`app`** (`ipcheck`, php-fpm) — mengeksekusi PHP. **Semua file PHP WAJIB di-mount ke kontainer ini** (bind mount, read-only), kalau tidak edit tidak akan kebaca (kopi build-time di image basi).
- **`web`** (`ipcheck-web`, nginx) — port publik `8090`, proxy ke `app:9000` via FastCGI.
- Port NPM → `8090` → nginx web → php-fpm.
- Env di `.env`: `ADMIN_PASSWORD` (default `ipcheck`), `TRUST_PROXY=true`, `TRUSTED_PROXIES=` (isikan IP/CIDR proxy tepercaya, mis. `192.168.0.0/16,172.30.0.1,127.0.0.1,100.64.0.0/10`), `APP_TIMEZONE=Asia/Jakarta`.
- Setelah edit file PHP tidak perlu rebuild image (sudah bind-mount), cukup otomatis live.
- Healthcheck aktif di kedua kontainer; `web` sudah `restart: unless-stopped`.

## Admin (rahasia)

- **Auth:** HTTP Basic Auth, user `admin`, password dari env `ADMIN_PASSWORD` (fallback `ipcheck`).
- **Tombol rahasia di `index.php`:** klik badge **"IP Address" 5x dalam 2 detik**, atau tekan **Ctrl+Shift+A** → redirect ke `admin.php`.
- Admin panel tidak di-link secara terlihat dari halaman publik (sengaja disembunyikan).

## Deteksi IP (RAHASIA BESAR — sudah diperbaiki)

Alur header saat lewat NPM:

1. NPM menambahkan IP client ke `X-Forwarded-For` (paling kiri) dan set `X-Real-IP` ke client.
2. Nginx web (internal) meng-overwrite `X-Real-IP` dengan `$remote_addr` (= IP NPM/host) dan menambahkan IP-nya ke kanan `X-Forwarded-For`.
3. **Aturan di `get_visitor_ip()` (`config.php`): baca `X-Forwarded-For` duluan, ambil IP valid PALING KIRI; `X-Real-IP` hanya fallback.**
4. `TRUSTED_PROXIES` mendukung CIDR (`ip_in_cidr()` + `is_trusted_proxy()`). Header cuma dihormati kalau `REMOTE_ADDR` ada di daftar tepercaya.

JANGAN balikin ke prioritas `X-Real-IP` dulu — hasilnya IP host/NPM, bukan client. Test cepat: `curl -H "X-Forwarded-For: 203.0.113.55, 172.17.0.1" http://localhost:8090/` → harus menampilkan `203.0.113.55`.

## Catatan penting

- Auto-refresh 30 detik di admin.php SUDAH DIHAPUS (bukan fitur lagi). Jangan dipasang ulang.
- Data dirotasi otomatis: `visitor_data.jsonl` & `visitor_details.jsonl` maks 10 MB, sisakan 2000 baris terakhir (`LOG_MAX_BYTES`, `LOG_KEEP_LINES` di config.php).
- `parse_data()` di admin.php: urut dari terbaru, pakai `tracked_at` kalau ada fallback ke `time`.
- `load_data()` key = `visit_id`, menggabungkan detail dari `visitor_details.jsonl` (last-wins). `ip_visit_count()` memakai cache `visitor_ip_seen.json` (dibump di `log_visitor`, di-rebuild saat rotasi).
- `track.php` append-only ke `visitor_details.jsonl` (tidak menulis ulang data utama). Baca IP untuk GeoIP dari ekor `visitor_data.jsonl` (`tail_lines()`), cek `has_detail` dulu biar tidak dobel.
- Admin panel punya: export CSV/JSON (`?export=csv|json`, ikut filter), grafik kunjungan per hari, warning banner kalau password masih default `ipcheck`.
- GeoIP: hanya untuk IP publik, sekali per kunjungan, via `ip-api.com` (timeout 3 detik).
- Admin panel memakai query params: `q` (cari), `range` (today/7/30/all), `page`.
- Hapus semua data = POST `action=clear` (bukan GET, biar tidak ke-trigger lewat URL).

## Perintah umum

```bash
docker compose up -d app        # apply perubahan mount / recreate
docker compose up -d --build    # rebuild image (jarang perlu)
docker exec ipcheck grep x /app/index.php  # cek file versi kontainer
curl http://localhost:8090/     # tes halaman
```
