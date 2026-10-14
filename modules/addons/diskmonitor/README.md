# Disk Monitor — Instalasi (MVP)

Addon WHMCS native (`modules/addons/diskmonitor/`) + agen opsional (`agent/agent.php`).
PHP 7.4-compatible. Threshold default: warning ≥85%, critical ≥95%, cooldown 24 jam, auto-resolve <80%.

## 1. Buat Email Template

1. WHMCS Admin → **Setup > Email Templates** → **Create New Email Template**.
2. **Name** (Message Name): `Disk Usage Warning` (harus persis — nilai default setting `email_template`).
3. **Subject** contoh: `Peringatan Disk cPanel {$domain} mencapai {$pct}%`.
4. Body: pakai variabel `{$client_name}`, `{$domain}`, `{$pct}`, `{$diskused}`, `{$quota}`, `{$cpanel_url}`.
5. **Save**. Template harus ada **sebelum aktivasi** — aktivasi gagal jika template belum dibuat.

## 2. Aktifkan modul

1. Salin folder `modules/addons/diskmonitor/` ke instalasi WHMCS Anda (path yang sama).
2. WHMCS Admin → **Setup > Addon Modules** → **Disk Monitor** → **Activate** → **Save Changes**.
3. Saat aktivasi: 3 tabel dibuat (`mod_diskmonitor_cache/events/log`) dan `agent_key_global` (secret 32-char hex) di-generate otomatis bila kosong.

## 3. Salin `agent_key_global`

1. **Setup > Addon Modules** → **Disk Monitor** → buka konfigurasi, salin nilai **Agent/Cron Key** (`agent_key_global`).
2. Key yang sama dipakai untuk sys cron (`cron.php`) dan agen (`ingest.php`): request tanpa key yang cocok ditolak 403.

## 4. System cron (polling WHM API, tiap 15 menit)

```cron
*/15 * * * * php -q /var/www/whmcs/modules/addons/diskmonitor/cron.php key=AGENT_KEY_GLOBAL_ANDA
```

Ganti `/var/www/whmcs` dengan path WHMCS Anda dan `AGENT_KEY_GLOBAL_ANDA` dengan key dari langkah 3.
Cek manual: jalankan perintah di atas sekali via SSH, harus mencetak JSON `{"servers_ok":..,"servers_failed":..,"emails_sent":..,"events_open":..}` (`events_open` = event berstatus open yang diproses run ini, termasuk yang baru dibuka).
Halaman admin (**Addons > Disk Monitor**) juga punya tombol **Check Now** untuk run manual + **overview** 50 event terbuka (open/unmapped) dan hitungan email 24 jam terakhir.

> Format host WHM: isi **Hostname/IP server** di WHMCS (tblservers) sebagai bare hostname, contoh `s1.contoh.com` —
> JANGAN sertakan scheme (`https://`), port (`:2087`), atau path (`/json-api/`).
> `WhmClient` membersihkan input defensif (strip scheme/port/path) lalu menambahkan `https://` + `:2087/json-api/` sendiri.

## 5. Agen cPanel (opsional, per server cPanel)

Untuk server yang tidak bisa dijangkau WHM API, deploy agen push:

1. Salin `agent/agent.php` ke `/root/diskmonitor-agent.php` di server cPanel.
2. Isi 3 nilai config di atas file:
   - `$ingestUrl` → `https://WHMCS_ANDA/modules/addons/diskmonitor/ingest.php`
   - `$serverKey` → key dari langkah 3
   - `$hostname` terisi otomatis via `hostname -f` (pastikan cocok dengan hostname server di WHMCS → `tblservers.hostname`, kalau tidak ingest balas 404 `unknown server`).
3. Root cron tiap 5 menit:

```cron
*/5 * * * * php /root/diskmonitor-agent.php
```

Agen hanya mengirim akun yang berubah ≥1% sejak run terakhir (state di `/root/.diskmonitor-state.json`); state ditulis hanya bila ingest balas HTTP 2xx. Payload: `{server_key, hostname, accounts:[{user, domain, diskused_mb, quota_mb, pct}]}` → ingest balas `{ok:true, accepted:N}`. Butuh `repquota`, `uapi`, dan ekstensi PHP `curl`.

**Presedensi cache agen:** bila tabel `mod_diskmonitor_cache` berisi baris `source='agent'` yang segar (umur <10 menit) untuk suatu server, `Runner` memakai baris tersebut dan MELEWATI polling WHM API untuk server itu. Cache basi (>10 menit) → fallback ke polling WHM normal.

**Throttle ingest:** `ingest.php` dibatasi 1 request/menit per kombinasi IP+hostname (file-based di `sys_get_temp_dir()`, balas HTTP 429 `too many requests` bila berlebih). Agen push tiap 5 menit tidak terdampak; volley/DoS teredam.

## 6. Troubleshooting

- **Unmapped (akun tanpa email, tanpa kirim):** user cPanel tidak cocok ke `tblclients` (cek pencocokan domain/email di overview → status `unmapped`). Daftarkan/mapping manual email klien, atau abaikan untuk akun sistem. Tidak ada email yang dikirim untuk unmapped.
- **Failed (Check Now/cron gagal):** cek `servers_failed` di output JSON/notice admin. Penyebab umum: API token WHM salah/kedaluwarsa, firewall port 2087, hostname salah format (lihat catatan `:2087` di atas), sertifikat SSL self-signed (agen/WHM client verifikasi SSL aktif), atau `agent_key_global` salah (403). Jalankan cron manual via SSH untuk melihat pesan error.
- **Email tidak datang:** pastikan template `Disk Usage Warning` ada dan aktif, cooldown 24 jam per event (email kedua tidak dikirim <24 jam), dan event masih `open` (turun <80% → `resolved`, tidak ada email resolved).
- **Ingest 404 `unknown server`:** `$hostname` agen harus sama persis dengan `tblservers.hostname`.

## 7. Retensi log (housekeeping harian `hooks.php`, via WHMCS DailyCronJob)

- `mod_diskmonitor_cache`: baris `updated_at` >7 hari dihapus.
- `mod_diskmonitor_log`: baris `created_at` >90 hari dihapus.
- `mod_diskmonitor_events`: event `open` dengan `last_seen` >7 hari ditandai `resolved`.
