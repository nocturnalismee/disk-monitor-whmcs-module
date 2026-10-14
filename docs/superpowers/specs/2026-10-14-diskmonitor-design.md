# Design Spec — WHMCS Addon Module: Disk Monitor (MVP)

- **Tanggal:** 2026-10-14
- **Status:** Draft — menunggu review user
- **Scope:** MVP — monitoring disk penuh akun cPanel + email otomatis ke pelanggan
- **Stack:** PHP 7.4–8.x (sintaks kompatibel 7.4, tanpa sintaks khusus PHP 8), WHMCS 8.x–9.1 (Capsule, localAPI, Smarty), MySQL/MariaDB, WHM API
- **Keputusan arsitektur:** Addon Module native WHMCS (bukan standalone app), dengan collector hybrid (WHM API poller + lightweight agent untuk server root)

---

## 1. Ringkasan Eksekutif

Penjual share hosting dengan banyak server WHM/cPanel yang terdaftar di WHMCS membutuhkan peringatan dini saat disk akun hosting pelanggan penuh. Hari ini hal itu diketahui manual (komplain pelanggan / cek satu per satu).

Modul ini menambahkan menu **Addons → Disk Monitor** di WHMCS Admin yang:

1. Setiap 15 menit mengumpulkan pemakaian disk per akun cPanel dari semua server aktif.
2. Mengevaluasi aturan threshold (warning 85%, critical 95%).
3. Mengambil email valid pelanggan dari `tblclients` (WHMCS sebagai source of truth).
4. Mengirim 1 email per akun per 24 jam via Email Template WHMCS.
5. Mencatat event + auto-resolve saat usage turun < 80%.

MVP **hanya** menangani disk. Spam/malware/tiket otomatis eksplisit ditunda ke Fase 2.

## 2. Latar Belakang & Masalah

- Banyak server WHM/cPanel, akses campuran (sebagian root SSH, sebagian hanya WHM API/token).
- Akun penuh menyebabkan website error, email gagal, backup gagal — pelanggan komplain setelah kejadian.
- Email pelanggan yang valid hanya ada di WHMCS (`tblclients.email`), bukan di server cPanel (sering usang).
- Kebutuhan: deteksi proaktif + notifikasi otomatis yang tidak spamming.

## 3. Tujuan & Non-Tujuan

### 3.1 Tujuan (MVP harus bisa)

- [ ] Poll disk usage semua akun di semua server cPanel aktif yang terdaftar di `tblservers`.
- [ ] Deteksi warning (≥ 85%) dan critical (≥ 95% atau over-quota).
- [ ] Kirim email otomatis ke email pelanggan dari WHMCS, maksimal 1x per akun per 24 jam (cooldown).
- [ ] Halaman admin: daftar akun bermasalah, status event, tombol Check Now + Send Test.
- [ ] Auto-resolve event saat usage < 80%.
- [ ] Log lengkap untuk audit (siapa dikirimi apa, kapan).

### 3.2 Non-Tujuan (tidak dikerjakan di MVP)

- Deteksi spam/outgoing email abuse, malware/compromised, load/CPU/RAM, SSL expiry.
- Buka tiket otomatis, suspend/unsuspend otomatis.
- Dashboard grafik historis (cukup tabel status + log).
- Billing/upgrade otomatis.

### 3.3 Kriteria Sukses

- Cron 15 menit mencakup 100% server enabled tanpa timeout fatal (server gagal dicatat, tidak menghentikan loop).
- Tidak ada akun yang dikirimi email duplikat dalam 24 jam untuk event yang sama.
- Email terkirim ke alamat yang sama dengan `tblclients.email` untuk `userid` pemilik hosting tersebut.

## 4. Arsitektur

### 4.1 Diagram

```
┌──────────────────┐  WHM API        ┌──────────────────────┐
│ Server WHM       │  listaccts /    │  WHMCS + Addon       │
│ non-root         │◄────────────────│  Disk Monitor        │
│ (tanpa agent)    │  accountsummary │                      │
└──────────────────┘                 │  Collector → Rules   │
                                     │  → Resolver →        │
┌──────────────────┐  HTTPS POST     │  Notifier (SendEmail)│
│ Server WHM root  │  JSON + key     │                      │
│ agent.php (cron  │────────────────►│  DB: mod_diskmonitor │◄── Admin UI
│ 5 mnt)           │  ingest.php     │  _* + tblservers /   │    (overview,
└──────────────────┘                 │  tblhosting /        │     settings,
                                     │  tblclients          │     logs)
                                     └──────────────────────┘
                                                │ cron 15 mnt (cron.php)
                                                ▼
                                         Email ke pelanggan
```

### 4.2 Keputusan Kunci + Alasan

| Keputusan | Alternatif yang ditolak | Alasan |
|---|---|---|
| Addon native WHMCS, bukan app standalone | App PHP terpisah + WHMCS API eksternal | Menyatu dengan auth admin, pakai Capsule + mailer WHMCS, tidak perlu server/DB tambahan; sesuai permintaan user |
| Collector hybrid (poller + agent) | Poller API saja | API saja tidak akurat untuk detail quota/inode di semua versi; agent 1-file memberi data presisi di server root tanpa membebani WHM API |
| Email via Email Template + `localAPI SendEmail` | SMTP langsung dari modul | Template bisa diedit admin tanpa deploy, riwayat masuk Email Log WHMCS, support multi-bahasa/brand |
| Cooldown 24 jam + auto-resolve 80% | Kirim tiap cron | Mencegah spam notifikasi; hysteresis 80% mencegah flapping 84↔86% |

## 5. Komponen & Struktur File

```
modules/addons/diskmonitor/
  diskmonitor.php          # _config(), _activate(), _deactivate(), _output()
  hooks.php                # hook DailyCronJob + fungsi runCheck() yang bisa dipanggil cron 15 mnt
  cron.php                 # entrypoint ringan untuk sys cron */15 (bootstrap WHMCS + runCheck), guard CLI/key
  ingest.php               # endpoint POST dari agent.php (validasi key, upsert cache, return JSON)
  lib/
    Collector.php          # class Collector: pollServer(), parseUsage(), loopEnabledServers()
    WhmClient.php          # wrapper cURL WHM API (token/accesshash, timeout, retry)
    Resolver.php           # class Resolver: map username/domain → hosting → client email
    Rules.php              # evaluate(pct): level none|warning|critical; shouldNotify(event)
    Notifier.php           # sendWarning(): localAPI SendEmail + insert log, hormati cooldown
  templates/
    overview.tpl           # tabel + filter + tombol aksi (Smarty, sesuai standar WHMCS)
  lang/
    indonesian.php         # string UI (wajib, karena user ID)
    english.php
agent/
  agent.php                # script 1-file di server cPanel: baca quota, POST ke ingest.php
```

### 5.1 Tanggung Jawab Tiap File

- **diskmonitor.php**: definisi `_config` (field: warn_pct, crit_pct, cooldown_h, email_template, enabled_only, agent_key_global), `_activate` (buat tabel), `_output` (render overview + tangani aksi `checknow`, `sendtest`).
- **hooks.php**: `add_hook('DailyCronJob', 1, 'diskmonitor_daily')` untuk housekeeping (resolve basi, hapus cache >7 hari). Pengecekan 15 mnt TIDAK lewat DailyCronJob (terlalu jarang) — lewat `cron.php` + sys cron.
- **lib/Collector.php**: satu-satunya tempat yang bicara WHM. Input: row `tblservers`. Output: array `[{user, domain, diskused_mb, quota_mb, pct}]` dinormalisasi (pct dibulatkan 1 desimal, quota 0/unlimited → pct dihitung dari batas konfig `unlimited_as_mb` atau tandai `unlimited=true` dan skip alert).
- **lib/WhmClient.php**: cURL dengan header `Authorization: whm user:token`, timeout 15 dtk, retry 2x + backoff, throw `WhmException` dengan server_id agar loop lanjut ke server berikut.
- **lib/Resolver.php**: urutan mapping: (1) `tblhosting.username = cpanel_user AND server = server_id` → `userid`; (2) fallback `tblhosting.domain = domain`; (3) fallback `tblclients` via `domain` di `tbldomains`. Ambil `firstname, email` dari `tblclients`. Jika tidak ketemu → event status `unmapped`, tidak kirim email, tampilkan di admin untuk dikoreksi manual.
- **lib/Rules.php**: pure function, tanpa I/O — mudah di-unit-test.
- **lib/Notifier.php**: cek cooldown (`last_sent_at`), panggil `localAPI('SendEmail', ['messagename' => template, 'id' => userid, 'customvars' => [...]])`, catat ke log. Jika SendEmail gagal → status `failed`, retry di cron berikut (maks 3x).
- **ingest.php**: terima `server_key, accounts[]`, validasi `hash_equals`, upsert `mod_diskmonitor_cache`, return `{ok, accepted}`. Tidak boleh memicu kirim email langsung — email hanya dari cron agar cooldown konsisten.
- **agent/agent.php**: dijalankan cron root tiap 5 menit, baca `/etc/quota` atau `repquota -a` / `uapi Quota_get_quota_info` per user, kirim ringkas (hanya user yang berubah >1% sejak kirim terakhir untuk hemat bandwidth).

## 6. Skema Database

Semua tabel custom berprefix `mod_diskmonitor_`, dibuat di `_activate()` dengan `Capsule::schema()`. Kolasi ikut default WHMCS (`utf8mb4_unicode_ci`).

```sql
CREATE TABLE mod_diskmonitor_cache (
  server_id INT NOT NULL,
  user VARCHAR(64) NOT NULL,
  domain VARCHAR(255) NOT NULL DEFAULT '',
  diskused_mb DECIMAL(12,2) NOT NULL DEFAULT 0,
  quota_mb DECIMAL(12,2) NOT NULL DEFAULT 0,
  pct DECIMAL(5,1) NOT NULL DEFAULT 0,
  unlimited TINYINT(1) NOT NULL DEFAULT 0,
  source ENUM('api','agent') NOT NULL DEFAULT 'api',
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (server_id, user),
  KEY idx_pct (pct)
);

CREATE TABLE mod_diskmonitor_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  server_id INT NOT NULL,
  user VARCHAR(64) NOT NULL,
  domain VARCHAR(255) NOT NULL DEFAULT '',
  level ENUM('warning','critical') NOT NULL,
  status ENUM('open','resolved','unmapped') NOT NULL DEFAULT 'open',
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  last_sent_at DATETIME NULL,
  send_count INT NOT NULL DEFAULT 0,
  resolved_at DATETIME NULL,
  KEY idx_open (status, server_id)
);

CREATE TABLE mod_diskmonitor_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NULL,
  server_id INT NOT NULL,
  user VARCHAR(64) NOT NULL,
  email_to VARCHAR(255) NOT NULL DEFAULT '',
  template VARCHAR(128) NOT NULL DEFAULT '',
  result ENUM('sent','skipped_cooldown','failed','unmapped') NOT NULL,
  message TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_event (event_id)
);
```

Retensi: `cache` diprune jika `updated_at > 7 hari`; `log` disimpan 90 hari (housekeeping harian).

## 7. Alur Data End-to-End

1. **Trigger**: sys cron `*/15 * * * * php -q /var/www/whmcs/modules/addons/diskmonitor/cron.php key=AGENT_KEY_GLOBAL_ANDA` (ganti path sesuai lokasi WHMCS Anda; key = nilai `agent_key_global` dari settings modul agar tidak bisa dipicu publik).
2. **Collect**: untuk tiap server (`tblservers` where `type='cpanel' AND disabled=0` + filter enabled_only):
   - Jika `mode=agent` dan cache agent segar (<10 mnt) → pakai cache.
   - Jika tidak → `Collector::pollServer()` via WHM API.
   - Server gagal → catat `log(result=failed)` + lanjut (jangan abort loop).
3. **Evaluate**: `Rules::evaluate(pct)` → cari/buka event di `events` ( kunci `server_id+user` status open).
   - Naik level (warning→critical) → update level + izinkan kirim lagi walau dalam cooldown (karena eskalasi).
   - Turun <80% → `resolved`.
4. **Resolve kontak**: `Resolver::forAccount()` → `userid, email, firstname`.
   - Tidak ketemu → `status=unmapped`, tampil di admin, stop (jangan kirim ke alamat tebak-tebakan).
5. **Notify**: jika `shouldNotify` (belum pernah kirim ATAU >cooldown ATAU eskalasi) → `localAPI SendEmail` dengan `customvars: domain, pct, diskused, quota, cpanel_url`.
   - Sukses → `last_sent_at=NOW(), send_count+1`.
   - Gagal → `log failed`, coba lagi cron berikut (maks 3x, lalu tandai perlu intervensi manual).
6. **Admin melihat**: overview menampilkan gabungan cache+events; aksi manual `Check Now` (jalankan sync 1 server) dan `Send Test` (kirim template ke email admin login).

### State Machine Event

```
(none) --pct>=85--> open/warning --pct>=95--> open/critical
   ^                      |                         |
   | pct<80%              | cooldown: skip          | cooldown: skip
   +---- resolved <-------+                         | (kecuali eskalasi)
                           +------- pct<80% → resolved
```

## 8. Aturan Deteksi (Konfigurasi)

| Setting (di `_config`) | Default | Keterangan |
|---|---|---|
| `warn_pct` | 85 | Warning jika pct ≥ ini dan < crit |
| `crit_pct` | 95 | Critical jika pct ≥ ini ATAU over-quota |
| `cooldown_h` | 24 | Jeda minimum antar email untuk event yang sama & level sama |
| `resolve_below` | 80 | Auto-resolve jika pct < ini (hysteresis) |
| `email_template` | `Disk Usage Warning` | Nama messagename di Setup → Email Templates |
| `enabled_only` | 1 | Hanya server yang dicentang di settings (hindari server migrasi ikut di-poll) |
| `unlimited_as_mb` | 10240 | Jika quota=unlimited/0, jangan alert; tampilkan pct sebagai NULL + badge Unlimited |

Validasi: `0 < warn < crit ≤ 100`, `cooldown 1–168 jam`. Nilai invalid → fallback default + tampilkan warning di admin.

## 9. Integrasi WHM/cPanel API

- **Kredensial**: TIDAK perlu token baru. Baca langsung dari `tblservers` (`hostname/ip, username, accesshash`) yang sudah diisi saat admin menambahkan server di Setup → Servers. Prioritas token API jika `accesshash` berisi token. Modul tidak menyimpan kredensial baru dan tidak meminta admin input ulang kredensial WHM.
- **Endpoint**:
  - `GET /json-api/listaccts?api.version=1` → daftar `user, domain, diskused, disklimit` (satuan MB, string seperti `"512M"` — parser harus tangani `M/G/K` + `unlimited`).
  - Fallback per akun jika field kosong: `GET /json-api/accountsummary?api.version=1&user=<user>` → `acct.diskused, disklimit`.
- **Normalisasi**: konversi semua ke MB (float), `pct = quota>0 ? diskused/quota*100 : NULL`. Bulatkan 1 desimal.
- **Batas**: batch maksimal 500 akun per panggilan; jika server >2000 akun, gunakan pagination `listaccts` (`limit/offset`) agar tidak timeout. Timeout cURL 15 dtk, retry 2x.
- **Mode agent** (server root): agent membaca lokal (lebih cepat + tidak kena rate-limit API):
  - Perintah: `repquota -a` atau `quota -u <user>`; alternatif `uapi --user=<user> Quota_get_quota_info --output=json`.
  - Agent hanya mengirim delta (>1% berubah) tiap 5 menit ke `ingest.php`.

## 10. Integrasi WHMCS

- **Baca server**: `Capsule::table('tblservers')->where('type','cpanel')->where('disabled',0)->get()`.
- **Baca akun**: `tblhosting (userid, server, username, domain)` join `tblclients (email, firstname)`.
- **Kirim email**: `localAPI('SendEmail', ['messagename' => $template, 'id' => $userid, 'customvars' => ['domain'=>..., 'pct'=>..., ...]], $adminUser)`. Jangan pakai `mail()` PHP langsung agar masuk Email Log + queue WHMCS.
- **Template**: admin wajib buat 1 Email Template tipe Product (`messagename = Disk Usage Warning`) sebelum aktivasi — `_activate()` mengecek dan menolak aktif dengan pesan jelas jika belum ada.
- **Bahasa**: sediakan `lang/indonesian.php` + `english.php`; `customvars` sama untuk dua bahasa.

## 11. Agent — Spesifikasi

- **File**: `agent/agent.php`, dependensi hanya PHP 7.4+ + curl, tanpa composer. Instalasi: salin 1 file + tambah cron root `*/5 * * * * php /root/diskmonitor-agent.php`.
- **Konfigurasi agent** (3 baris di atas file): `$ingestUrl, $serverKey, $hostname`.
- **Payload**:
```json
{
  "server_key": "xxx",
  "hostname": "srv1.example.com",
  "accounts": [{"user":"pelanggan1","domain":"toko.com","diskused_mb":920.5,"quota_mb":1000,"pct":92.1}]
}
```
- **Keamanan**: key acak 32 char per server (disimpan di settings modul + di agent); `hash_equals`, HTTPS wajib, rate-limit 1 req/menit/IP di `ingest.php`, tolak payload >2MB.

## 12. Template Email (Isi Baku)

- **Subject**: `[Peringatan] Hosting {domain} hampir penuh ({pct}%)`
- **Body (ID)** — variabel: `{$client_name}, {$domain}, {$pct}%, {$diskused} MB / {$quota} MB`:
  > Halo {$client_name}, hosting {$domain} Anda telah memakai {$pct}% ({$diskused} dari {$quota} MB). Jika penuh, website bisa error dan email gagal masuk. Mohon hapus backup/file log lama via cPanel → File Manager / Disk Usage, atau balas email ini untuk dibantu upgrade paket. — Tim Hosting
- **CTA**: link cPanel (`https://{server_hostname}:2083`), link Disk Usage tutorial (URL konfig), balas email.
- Level critical menambah kalimat: "tersisa <5%, segera bertindak."

## 13. Admin UI (`_output`)

- **Header**: ringkasan (server dipoll X/Y, akun warning N, critical M, email 24 jam terakhir K, server gagal L).
- **Tabel**: Server | User | Domain | Usage | Quota | % (badge warna) | Level | Status event | Terakhir kirim | Aksi (Check, Send Test).
- **Filter**: by server, level, status; search user/domain.
- **Aksi**: `Check Now` (sync 1 server, tampilkan hasil), `Send Test` (kirim template ke email admin yang login).
- Tidak ada grafik di MVP (hemat scope).

## 14. Cron & Operasional

- **Sys cron wajib**: `*/15 * * * * php -q /var/www/whmcs/modules/addons/diskmonitor/cron.php key=GLOBALKEY >/dev/null 2>&1`. Tanpa ini modul hanya jalan harian (tidak cukup).
- **Housekeeping harian** via `DailyCronJob`: resolve event basi (>7 hari tidak terlihat → resolved), prune cache/log.
- **Instalasi**: upload folder → Setup → Addon Modules → Activate → isi settings → buat Email Template → tambah sys cron → jalankan Check Now → verifikasi 1 email test.
- **Monitoring modul itu sendiri**: jika `cron.php` tidak jalan >1 jam → tampilkan banner merah di admin ("Cron belum jalan, cek sys cron").

## 15. Keamanan

- `cron.php` + `ingest.php` menolak akses tanpa key benar (403, tanpa bocorkan alasan).
- Semua query via Capsule prepared statement; output admin di-escape (`htmlspecialchars`).
- Key agent 32 char, rotasi manual via settings (tombol Regenerate).
- Tidak menyimpan password cPanel baru; pakai yang sudah ada di `tblservers`.
- Log tidak menyimpan isi email penuh, hanya metadata (to, template, result).

## 16. Error Handling & Logging

| Kejadian | Perilaku |
|---|---|
| 1 server timeout/gagal auth | Catat `log failed`, lanjut server lain; tampilkan badge merah di overview + counter gagal |
| WHMCS SendEmail gagal | Retry 3x di cron berikut; setelah itu status tetap open + flag `needs_attention` |
| Akun tidak termapping ke klien | `unmapped`, tidak kirim email, tampil di tab khusus untuk dikoreksi |
| Payload agent invalid | 400 JSON `{error}`, tidak simpan apa pun |
| Settings invalid | Fallback default + banner kuning di admin |

## 17. Testing Plan

1. **Unit (tanpa WHMCS)**: `Rules::evaluate` — 84.9→none, 85→warning, 94.9→warning, 95→critical, over-quota→critical; `shouldNotify` — kirim pertama true, <24 jam false, eskalasi true, resolved false.
2. **Integrasi lokal**: mock `WhmClient` (JSON fixture `listaccts`) + SQLite/Capsule palsu → pastikan parsing `512M/1G/unlimited` benar.
3. **Staging WHMCS**: 1 server test + 2 produk dummy (satu 86%, satu 97%) → cron manual → verifikasi 2 email masuk ke mailbox test + tidak ada kirim ulang dalam 24 jam.
4. **UAT**: admin klik Check Now + Send Test; verifikasi Email Log WHMCS + overview akurat.
5. **Beban**: simulasi 5 server × 500 akun → cron < 5 menit (jika lebih, aktifkan pagination + naikkan timeout).

## 18. Rollout Plan (3 Tahap)

- **Tahap 1 (minggu 1)**: modul + poller API saja, 1–2 server pilot, threshold 90/97 sementara (longgar) untuk uji tanpa banjir email.
- **Tahap 2 (minggu 2)**: turunkan ke 85/95, deploy agent ke server root, tambah sys cron 15 mnt di produksi.
- **Tahap 3 (minggu 3)**: rollout semua server enabled, dokumentasi SOP admin (cara handle unmapped/failed).

## 19. Fase Lanjutan (di luar spec ini)

- Spam: baca Exim queue + outgoing per akun → email peringatan abuse.
- Malware: integrasi ImunifyAV/maldet scan result → email + tiket.
- Opsi auto-tiket (`OpenTicket`) sebagai alternatif email langsung.
- Grafik histori + notifikasi Telegram/WA admin.

## 20. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Banyak server → cron >15 mnt | Data basi | Pagination + skip cache agent segar + cron per-server staggered |
| Username/domain tidak cocok tblhosting | Email tidak terkirim (unmapped) | Tab unmapped + fallback 3 lapis + SOP koreksi manual |
| Upgrade WHMCS merusak hook | Modul mati | Ikuti API stabil (Capsule/localAPI), tanpa override core; uji di staging tiap upgrade |
| Banjir email jika banyak akun penuh bersamaan | Reputasi SMTP turun | Cooldown + batch max 50 email/cron + jeda 1 dtk antar kirim |

## 21. Pertanyaan Terbuka (dibutuhkan saat implementasi)

1. Versi WHMCS + PHP berapa? (menentukan sintaks Capsule/Smarty yang didukung)
2. Token WHM sudah API token atau masih accesshash lama?
3. Nama Email Template yang diinginkan + bahasa utama (ID saja atau ID+EN)?
4. Server mana untuk pilot (1–2 hostname)?
5. Sys cron 15 menit diizinkan di server WHMCS?

## 22. Acceptance Criteria (checklist serah terima)

- [ ] Activate modul tanpa error di WHMCS yang ditargetkan.
- [ ] Check Now 1 server pilot menampilkan data benar (cocok dengan cPanel Disk Usage).
- [ ] Akun 85–94% menerima 1 email warning; akun ≥95% menerima email critical.
- [ ] Tidak ada email kedua dalam 24 jam untuk akun yang sama & level sama.
- [ ] Akun turun <80% berstatus resolved otomatis.
- [ ] Akun tak termapping muncul di tab Unmapped, tanpa email terkirim.
- [ ] Dokumentasi instalasi + SOP ada di `modules/addons/diskmonitor/README.md`.

---

*Spec ini menggantikan desain standalone sebelumnya (2026-10-14). Perubahan disetujui user: standalone → Addon Module native; scope MVP dipersempit ke disk-penuh saja.*
