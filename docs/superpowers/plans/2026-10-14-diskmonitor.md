# Disk Monitor (WHMCS Addon) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a native WHMCS addon module that detects full cPanel disks (warning ≥85%, critical ≥95%) and emails the customer once per 24h via a WHMCS email template.

**Architecture:** WHMCS addon in `modules/addons/diskmonitor/` reuses `tblservers` credentials (no new WHM tokens). A 15-min sys cron polls WHM API per enabled server (fallback: lightweight `agent.php` push on root servers), pure-logic classes (`Rules`, `QuotaParser`, `Resolver`, `Notifier` decider) stay framework-free so they are PHPUnit-testable, and only thin wrappers touch Capsule/localAPI.

**Tech Stack:** PHP 7.4–8.x (kode harus sintaks-kompatibel PHP 7.4: tanpa `match`, enum, `readonly`, named args, `str_contains`), WHMCS 8.x–9.1 (hanya API stabil: Capsule query builder, `localAPI('SendEmail')`, `add_hook('DailyCronJob')`, Smarty `.tpl`), MySQL/MariaDB, PHPUnit 9.6 (dev only, jalan di PHP 7.4+), cURL untuk WHM API.

**Spec:** `docs/superpowers/specs/2026-10-14-diskmonitor-design.md`

## Global Constraints

- Platform: PHP 7.4–8.x, WHMCS 8.x–9.1. Semua kode modul harus lolos `php -l` di PHP 7.4 dan tanpa deprecation di PHP 8.1+ (cast ke string sebelum `strtolower`, tanpa passing `null` ke parameter non-nullable).
- Thresholds: `warn_pct=85`, `crit_pct=95`, `cooldown_h=24`, `resolve_below=80` (settings-overridable, validated `0 < warn < crit ≤ 100`).
- Custom tables: `mod_diskmonitor_cache` (PK `server_id,user`), `mod_diskmonitor_events`, `mod_diskmonitor_log` — exact schemas per spec §6.
- Email only via `localAPI('SendEmail', ['messagename' => <template>, 'id' => <userid>, 'customvars' => [...]])`; template name default `Disk Usage Warning`.
- Customer email resolved only from `tblclients` via `tblhosting` mapping (3-step fallback per spec §5.1); unmapped accounts never emailed.
- `ingest.php`/`cron.php` require key (`hash_equals`), HTTPS expected, payload cap 2MB.
- PHP files: `<?php` opening, no closing `?>` in pure-PHP files; admin output escaped via `htmlspecialchars`.

---

## File Map (final tree — locked by this plan)

```
modules/addons/diskmonitor/
  diskmonitor.php        # Task 1: _config/_activate/_deactivate/_output + settings validation
  hooks.php              # Task 6: DailyCronJob housekeeping (prune cache/log, resolve stale)
  cron.php               # Task 6: sys-cron entrypoint (key guard → runCheck)
  ingest.php             # Task 6: agent push endpoint (key guard → upsert cache)
  lib/Rules.php          # Task 2: evaluate() + shouldNotify() — pure
  lib/QuotaParser.php    # Task 3: toMb() + pct() — pure
  lib/WhmClient.php      # Task 3: cURL wrapper, listAccounts()/accountSummary()
  lib/Collector.php      # Task 3: normalize() + pollServer() — pure except injected client
  lib/Resolver.php       # Task 4: resolve() from hosting rows — pure
  lib/Notifier.php       # Task 5: decide() + buildCustomVars() — pure; send via injected $mailer
  lib/Runner.php         # Task 6: runCheck() orchestration (Capsule + localAPI here only)
  templates/overview.tpl # Task 6: admin table
  lang/indonesian.php    # Task 6
  lang/english.php       # Task 6
agent/agent.php          # Task 7
tests/
  RulesTest.php          # Task 2
  QuotaParserTest.php    # Task 3
  CollectorTest.php      # Task 3
  ResolverTest.php       # Task 4
  NotifierTest.php       # Task 5
phpunit.xml              # Task 2
composer.json            # Task 2 (require-dev phpunit only)
modules/addons/diskmonitor/README.md  # Task 7 (install + SOP)
```

Dependency order: Task 1 → 2 → 3 → 4 → 5 → 6 → 7. Each task's **Interfaces** block names exact signatures the next task consumes.

---

### Task 1: Module scaffold + activation (tables + config)

**Files:**
- Create: `modules/addons/diskmonitor/diskmonitor.php`
- Test: `php -l` + activation SQL dry-run via sqlite3 in-memory

**Interfaces:**
- Consumes: nothing (first task)
- Produces: `diskmonitor_config(): array` with keys `name, description, version, author, fields{warn_pct,crit_pct,cooldown_h,resolve_below,email_template,enabled_only,agent_key_global}`; `diskmonitor_activate(): void` creating the 3 tables; `diskmonitor_validate_settings(array $s): array` returning sanitized settings with defaults on invalid input.

- [ ] **Step 1: Write the config + validation test script**

```php
<?php
// /tmp/scaffold_check.php — run with: php /tmp/scaffold_check.php
require 'modules/addons/diskmonitor/diskmonitor.php';
$c = diskmonitor_config();
assert($c['fields']['warn_pct']['Default'] === '85');
assert($c['fields']['crit_pct']['Default'] === '95');
$s = diskmonitor_validate_settings(['warn_pct' => '90', 'crit_pct' => '80', 'cooldown_h' => 'abc']);
assert($s['warn_pct'] === 85 && $s['crit_pct'] === 95 && $s['cooldown_h'] === 24, 'invalid falls back to defaults');
echo "scaffold OK\n";
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php /tmp/scaffold_check.php`
Expected: FAIL with "Failed opening required 'modules/addons/diskmonitor/diskmonitor.php'".

- [ ] **Step 3: Write minimal `diskmonitor.php` (config + validate + activate/deactivate + stub output)**

```php
<?php
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function diskmonitor_config() {
    return [
        'name' => 'Disk Monitor',
        'description' => 'Peringatan disk penuh akun cPanel via email WHMCS.',
        'version' => '0.1.0',
        'author' => 'Nocturnalisme',
        'fields' => [
            'warn_pct' => ['FriendlyName' => 'Warning %', 'Type' => 'text', 'Size' => '5', 'Default' => '85', 'Description' => 'Peringatan jika >= nilai ini'],
            'crit_pct' => ['FriendlyName' => 'Critical %', 'Type' => 'text', 'Size' => '5', 'Default' => '95', 'Description' => 'Kritis jika >= nilai ini'],
            'cooldown_h' => ['FriendlyName' => 'Cooldown (jam)', 'Type' => 'text', 'Size' => '5', 'Default' => '24', 'Description' => 'Jeda minimum antar email per event (1-168)'],
            'resolve_below' => ['FriendlyName' => 'Auto-resolve di bawah %', 'Type' => 'text', 'Size' => '5', 'Default' => '80', 'Description' => 'Event resolved jika pct < nilai ini'],
            'email_template' => ['FriendlyName' => 'Nama Email Template', 'Type' => 'text', 'Size' => '30', 'Default' => 'Disk Usage Warning', 'Description' => 'Messagename di Setup > Email Templates'],
            'enabled_only' => ['FriendlyName' => 'Hanya server terpilih', 'Type' => 'yesno', 'Description' => 'Centang untuk batasi via daftar server (fase awal: semua enabled)'],
            'agent_key_global' => ['FriendlyName' => 'Agent/Cron Key', 'Type' => 'text', 'Size' => '40', 'Default' => '', 'Description' => 'Secret 32 char untuk cron.php & ingest.php. Kosongkan untuk generate saat aktivasi.'],
        ],
    ];
}

function diskmonitor_validate_settings(array $raw) {
    $warn = isset($raw['warn_pct']) ? (float) $raw['warn_pct'] : 85;
    $crit = isset($raw['crit_pct']) ? (float) $raw['crit_pct'] : 95;
    $cool = isset($raw['cooldown_h']) ? (int) $raw['cooldown_h'] : 24;
    $reso = isset($raw['resolve_below']) ? (float) $raw['resolve_below'] : 80;
    if (!($warn > 0 && $warn < $crit && $crit <= 100)) {
        $warn = 85;
        $crit = 95;
    }
    if ($cool < 1 || $cool > 168) {
        $cool = 24;
    }
    if ($reso <= 0 || $reso >= $warn) {
        $reso = 80;
    }
    return [
        'warn_pct' => $warn,
        'crit_pct' => $crit,
        'cooldown_h' => $cool,
        'resolve_below' => $reso,
        'email_template' => isset($raw['email_template']) && $raw['email_template'] !== '' ? (string) $raw['email_template'] : 'Disk Usage Warning',
    ];
}

function diskmonitor_activate() {
    $pdo = new PDO('mysql:host=' . $_SERVER['DB_HOST'] . ';dbname=' . $_SERVER['DB_NAME'], $_SERVER['DB_USER'], $_SERVER['DB_PASS']);
    // NOTE: di WHMCS asli gunakan Capsule::schema(); PDO di sini agar testable standalone.
    // Executor: ganti body dengan Capsule saat deploy (lihat Task 6), SQL di bawah tetap sama.
    $pdo->exec("CREATE TABLE IF NOT EXISTS mod_diskmonitor_cache (server_id INT NOT NULL, user VARCHAR(64) NOT NULL, domain VARCHAR(255) NOT NULL DEFAULT '', diskused_mb DECIMAL(12,2) NOT NULL DEFAULT 0, quota_mb DECIMAL(12,2) NOT NULL DEFAULT 0, pct DECIMAL(5,1) NOT NULL DEFAULT 0, unlimited TINYINT(1) NOT NULL DEFAULT 0, source ENUM('api','agent') NOT NULL DEFAULT 'api', updated_at DATETIME NOT NULL, PRIMARY KEY (server_id, user), KEY idx_pct (pct))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mod_diskmonitor_events (id INT AUTO_INCREMENT PRIMARY KEY, server_id INT NOT NULL, user VARCHAR(64) NOT NULL, domain VARCHAR(255) NOT NULL DEFAULT '', level ENUM('warning','critical') NOT NULL, status ENUM('open','resolved','unmapped') NOT NULL DEFAULT 'open', first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, last_sent_at DATETIME NULL, send_count INT NOT NULL DEFAULT 0, resolved_at DATETIME NULL, KEY idx_open (status, server_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mod_diskmonitor_log (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NULL, server_id INT NOT NULL, user VARCHAR(64) NOT NULL, email_to VARCHAR(255) NOT NULL DEFAULT '', template VARCHAR(128) NOT NULL DEFAULT '', result ENUM('sent','skipped_cooldown','failed','unmapped') NOT NULL, message TEXT NULL, created_at DATETIME NOT NULL, KEY idx_event (event_id))");
}

function diskmonitor_deactivate() {
}

function diskmonitor_output($vars) {
    echo '<h2>Disk Monitor</h2><p>Modul aktif. Halaman overview dibangun di Task 6.</p>';
}
```

- [ ] **Step 4: Run checks**

Run: `php -l modules/addons/diskmonitor/diskmonitor.php && php /tmp/scaffold_check.php`
Expected: `No syntax errors` + `scaffold OK`.

- [ ] **Step 5: Commit**

```bash
git add modules/addons/diskmonitor/diskmonitor.php
git commit -m "feat: scaffold diskmonitor addon with config and activation"
```

---

### Task 2: Rules engine (pure) + PHPUnit setup

**Files:**
- Create: `modules/addons/diskmonitor/lib/Rules.php`, `tests/RulesTest.php`, `phpunit.xml`, `composer.json`
- Test: `tests/RulesTest.php`

**Interfaces:**
- Consumes: settings array from `diskmonitor_validate_settings()` (Task 1)
- Produces: `DiskMonitor\Rules::evaluate(float $pct, bool $overQuota, float $warn, float $crit): string` (`'none'|'warning'|'critical'`); `DiskMonitor\Rules::shouldNotify(?string $lastSentAt, int $cooldownH, bool $escalated, string $now): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Rules;

final class RulesTest extends TestCase {
    public function testEvaluate(): void {
        $this->assertSame('none', Rules::evaluate(84.9, false, 85, 95));
        $this->assertSame('warning', Rules::evaluate(85.0, false, 85, 95));
        $this->assertSame('warning', Rules::evaluate(94.9, false, 85, 95));
        $this->assertSame('critical', Rules::evaluate(95.0, false, 85, 95));
        $this->assertSame('critical', Rules::evaluate(50.0, true, 85, 95));
    }

    public function testShouldNotify(): void {
        $this->assertTrue(Rules::shouldNotify(null, 24, false, '2026-10-14 10:00:00'));
        $this->assertFalse(Rules::shouldNotify('2026-10-14 09:00:00', 24, false, '2026-10-14 10:00:00'));
        $this->assertTrue(Rules::shouldNotify('2026-10-13 09:00:00', 24, false, '2026-10-14 10:00:00'));
        $this->assertTrue(Rules::shouldNotify('2026-10-14 09:00:00', 24, true, '2026-10-14 10:00:00'));
    }
}
```

`phpunit.xml`:
```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
  <testsuites><testsuite name="diskmonitor"><directory>tests</directory></testsuite></testsuites>
</phpunit>
```
`tests/bootstrap.php`: `<?php require __DIR__ . '/../modules/addons/diskmonitor/lib/Rules.php';` (task berikut append require-nya).
`composer.json`: `{"name":"nocturnalisme/disk-monitor-whmcs-module","require-dev":{"phpunit/phpunit":"^9.6"}}`

- [ ] **Step 2: Run test to verify it fails**

Run: `composer install --no-interaction -q 2>&1 | tail -1; vendor/bin/phpunit tests/RulesTest.php`
Expected: FAIL with "Class 'DiskMonitor\Rules' not found".

- [ ] **Step 3: Write minimal implementation**

```php
<?php
namespace DiskMonitor;

class Rules {
    public static function evaluate(float $pct, bool $overQuota, float $warn, float $crit): string {
        if ($overQuota || $pct >= $crit) {
            return 'critical';
        }
        if ($pct >= $warn) {
            return 'warning';
        }
        return 'none';
    }

    public static function shouldNotify(?string $lastSentAt, int $cooldownH, bool $escalated, string $now): bool {
        if ($lastSentAt === null || $lastSentAt === '') {
            return true;
        }
        if ($escalated) {
            return true;
        }
        $elapsed = strtotime($now) - strtotime($lastSentAt);
        return $elapsed >= $cooldownH * 3600;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/RulesTest.php`
Expected: `OK (2 tests, 9 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add modules/addons/diskmonitor/lib/Rules.php tests/RulesTest.php tests/bootstrap.php phpunit.xml composer.json
git commit -m "feat: add Rules engine with cooldown logic and tests"
```

---

### Task 3: WHM client + quota parsing + collector normalize

**Files:**
- Create: `modules/addons/diskmonitor/lib/QuotaParser.php`, `modules/addons/diskmonitor/lib/WhmClient.php`, `modules/addons/diskmonitor/lib/Collector.php`
- Test: `tests/QuotaParserTest.php`, `tests/CollectorTest.php`

**Interfaces:**
- Consumes: `Rules::evaluate()` (Task 2)
- Produces: `DiskMonitor\QuotaParser::toMb(string $raw): ?float` (null = unlimited/unparseable); `::pct(?float $usedMb, ?float $quotaMb): ?float`; `DiskMonitor\WhmClient::__construct(string $host, string $user, string $token, int $timeout = 15)` + `->listAccounts(): array` (raw WHM `acct` rows) + `->request(string $fn, array $params): array`; `DiskMonitor\Collector::normalize(array $rawAcct): array` returning `['user','domain','diskused_mb','quota_mb','pct','unlimited','over_quota']`

- [ ] **Step 1: Write the failing tests**

```php
<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\QuotaParser;
use DiskMonitor\Collector;

final class QuotaParserTest extends TestCase {
    public function testToMb(): void {
        $this->assertSame(512.0, QuotaParser::toMb('512M'));
        $this->assertSame(1024.0, QuotaParser::toMb('1G'));
        $this->assertSame(1.5, QuotaParser::toMb('1536K'));
        $this->assertNull(QuotaParser::toMb('unlimited'));
        $this->assertNull(QuotaParser::toMb('0'));
        $this->assertNull(QuotaParser::toMb(''));
    }

    public function testPct(): void {
        $this->assertSame(92.1, QuotaParser::pct(920.5, 1000.0));
        $this->assertNull(QuotaParser::pct(100.0, null));
    }
}

final class CollectorTest extends TestCase {
    public function testNormalize(): void {
        $n = Collector::normalize(['user' => 'toko', 'domain' => 'toko.com', 'diskused' => '920M', 'disklimit' => '1G']);
        $this->assertSame('toko', $n['user']);
        $this->assertSame(920.0, $n['diskused_mb']);
        $this->assertSame(1024.0, $n['quota_mb']);
        $this->assertSame(89.8, $n['pct']);
        $this->assertFalse($n['unlimited']);
        $this->assertFalse($n['over_quota']);

        $u = Collector::normalize(['user' => 'bebas', 'domain' => 'bebas.com', 'diskused' => '500M', 'disklimit' => 'unlimited']);
        $this->assertTrue($u['unlimited']);
        $this->assertNull($u['pct']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/QuotaParserTest.php tests/CollectorTest.php`
Expected: FAIL with "Class 'DiskMonitor\QuotaParser' not found".

- [ ] **Step 3: Write minimal implementations**

```php
<?php
namespace DiskMonitor;

class QuotaParser {
    public static function toMb(string $raw): ?float {
        $s = strtolower(trim($raw));
        if ($s === '' || $s === '0' || $s === 'unlimited' || $s === 'unmetered') {
            return null;
        }
        if (!preg_match('/^([\d.]+)\s*([kmg]?)$/', $s, $m)) {
            return null;
        }
        $v = (float) $m[1];
        switch ($m[2]) {
            case 'k': return round($v / 1024, 2);
            case 'g': return round($v * 1024, 2);
            default: return round($v, 2);
        }
    }

    public static function pct(?float $usedMb, ?float $quotaMb): ?float {
        if ($usedMb === null || $quotaMb === null || $quotaMb <= 0) {
            return null;
        }
        return round($usedMb / $quotaMb * 100, 1);
    }
}
```

```php
<?php
namespace DiskMonitor;

class WhmClient {
    private $host;
    private $user;
    private $token;
    private $timeout;

    public function __construct(string $host, string $user, string $token, int $timeout = 15) {
        $this->host = rtrim($host, '/');
        $this->user = $user;
        $this->token = $token;
        $this->timeout = $timeout;
    }

    public function request(string $fn, array $params = []): array {
        $url = $this->host . ':2087/json-api/' . $fn . '?api.version=1&' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Authorization: whm ' . $this->user . ':' . $this->token],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException('WHM request failed (' . $fn . '): HTTP ' . $code . ' ' . $err);
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('WHM request failed (' . $fn . '): invalid JSON');
        }
        return $data;
    }

    public function listAccounts(): array {
        $data = $this->request('listaccts');
        if (isset($data['data']['acct']) && is_array($data['data']['acct'])) {
            return $data['data']['acct'];
        }
        return [];
    }

    public function accountSummary(string $user): array {
        $data = $this->request('accountsummary', ['user' => $user]);
        if (isset($data['data']['acct']) && is_array($data['data']['acct'])) {
            return $data['data']['acct'];
        }
        return [];
    }
}
```

```php
<?php
namespace DiskMonitor;

class Collector {
    public static function normalize(array $rawAcct): array {
        $user = (string) ($rawAcct['user'] ?? '');
        $domain = (string) ($rawAcct['domain'] ?? '');
        $used = QuotaParser::toMb((string) ($rawAcct['diskused'] ?? ''));
        $quota = QuotaParser::toMb((string) ($rawAcct['disklimit'] ?? ''));
        $unlimited = $quota === null;
        return [
            'user' => $user,
            'domain' => $domain,
            'diskused_mb' => $used ?? 0.0,
            'quota_mb' => $quota ?? 0.0,
            'pct' => $unlimited ? null : QuotaParser::pct($used, $quota),
            'unlimited' => $unlimited,
            'over_quota' => !$unlimited && $used !== null && $quota !== null && $used >= $quota,
        ];
    }

    public static function pollServer(array $server, WhmClient $client): array {
        void($server);
        $out = [];
        foreach ($client->listAccounts() as $acct) {
            $out[] = self::normalize(is_array($acct) ? $acct : []);
        }
        return $out;
    }
}

function void($v) {
}
```

NOTE executor: hapus helper `void()` — itu placeholder agar `$server` tidak unused. Ganti `pollServer` dengan versi yang memakai `$server['id']` untuk logging di Task 6 jika diperlukan; untuk Task 3 biarkan, tapi hapus fungsi `void` dan tambahkan `$serverId = (int)($server['id'] ?? 0);` tanpa dipakai? Jangan — PHP tidak error untuk param unused. Versi final:

```php
public static function pollServer(array $server, WhmClient $client): array {
    $out = [];
    foreach ($client->listAccounts() as $acct) {
        $row = self::normalize(is_array($acct) ? $acct : []);
        $row['server_id'] = (int) ($server['id'] ?? 0);
        $out[] = $row;
    }
    return $out;
}
```

(Gunakan versi final ini, bukan yang memakai `void()`.)

- [ ] **Step 4: Register new classes in `tests/bootstrap.php`, run tests**

Append ke `tests/bootstrap.php`:
```php
require __DIR__ . '/../modules/addons/diskmonitor/lib/QuotaParser.php';
require __DIR__ . '/../modules/addons/diskmonitor/lib/WhmClient.php';
require __DIR__ . '/../modules/addons/diskmonitor/lib/Collector.php';
```

Run: `vendor/bin/phpunit tests/`
Expected: `OK` — semua test Task 2 + 3 hijau.

- [ ] **Step 5: Commit**

```bash
git add modules/addons/diskmonitor/lib/QuotaParser.php modules/addons/diskmonitor/lib/WhmClient.php modules/addons/diskmonitor/lib/Collector.php tests/QuotaParserTest.php tests/CollectorTest.php tests/bootstrap.php
git commit -m "feat: add WHM client, quota parser, and collector normalize"
```

---

### Task 4: Resolver (account → customer email, pure)

**Files:**
- Create: `modules/addons/diskmonitor/lib/Resolver.php`
- Test: `tests/ResolverTest.php`

**Interfaces:**
- Consumes: normalized account rows from `Collector::normalize()` (Task 3)
- Produces: `DiskMonitor\Resolver::resolve(array $account, array $hostingRows): ?array` — `$account` = `['user','domain','server_id']`; `$hostingRows` = rows `tblhosting` join `tblclients` (`['server','username','domain','userid','email','firstname']`); returns `['userid'=>int,'email'=>string,'firstname'=>string]` or `null` (unmapped). Fallback order: (1) server+username, (2) domain, (3) username only.

- [ ] **Step 1: Write the failing test**

```php
<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Resolver;

final class ResolverTest extends TestCase {
    private $rows;

    protected function setUp(): void {
        $this->rows = [
            ['server' => 1, 'username' => 'toko', 'domain' => 'toko.com', 'userid' => 7, 'email' => 'budi@toko.com', 'firstname' => 'Budi'],
            ['server' => 2, 'username' => 'lain', 'domain' => 'lain.com', 'userid' => 9, 'email' => 'sari@lain.com', 'firstname' => 'Sari'],
        ];
    }

    public function testExactMatch(): void {
        $r = Resolver::resolve(['user' => 'toko', 'domain' => 'toko.com', 'server_id' => 1], $this->rows);
        $this->assertSame(7, $r['userid']);
        $this->assertSame('budi@toko.com', $r['email']);
    }

    public function testDomainFallback(): void {
        $r = Resolver::resolve(['user' => 'toko_lama', 'domain' => 'toko.com', 'server_id' => 5], $this->rows);
        $this->assertSame(7, $r['userid']);
    }

    public function testUsernameFallback(): void {
        $r = Resolver::resolve(['user' => 'lain', 'domain' => 'pindah.com', 'server_id' => 5], $this->rows);
        $this->assertSame(9, $r['userid']);
    }

    public function testUnmapped(): void {
        $this->assertNull(Resolver::resolve(['user' => 'hantu', 'domain' => 'hantu.com', 'server_id' => 5], $this->rows));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ResolverTest.php`
Expected: FAIL with "Class 'DiskMonitor\Resolver' not found".

- [ ] **Step 3: Write minimal implementation**

```php
<?php
namespace DiskMonitor;

class Resolver {
    public static function resolve(array $account, array $hostingRows): ?array {
        $user = strtolower(trim((string) ($account['user'] ?? '')));
        $domain = strtolower(trim((string) ($account['domain'] ?? '')));
        $serverId = (int) ($account['server_id'] ?? 0);

        $pick = function (array $row): array {
            return [
                'userid' => (int) $row['userid'],
                'email' => (string) $row['email'],
                'firstname' => (string) ($row['firstname'] ?? ''),
            ];
        };

        foreach ($hostingRows as $row) {
            if ((int) $row['server'] === $serverId && strtolower((string) $row['username']) === $user) {
                return $pick($row);
            }
        }
        if ($domain !== '') {
            foreach ($hostingRows as $row) {
                if (strtolower((string) $row['domain']) === $domain) {
                    return $pick($row);
                }
            }
        }
        foreach ($hostingRows as $row) {
            if (strtolower((string) $row['username']) === $user) {
                return $pick($row);
            }
        }
        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/`
Expected: `OK` — semua test hijau.

- [ ] **Step 5: Commit**

```bash
git add modules/addons/diskmonitor/lib/Resolver.php tests/ResolverTest.php tests/bootstrap.php
git commit -m "feat: add account-to-customer resolver with fallbacks"
```

(Executor: tambahkan `require .../lib/Resolver.php';` ke `tests/bootstrap.php` sebelum run.)

---

### Task 5: Notifier decider (pure) — cooldown + eskalasi + customvars

**Files:**
- Create: `modules/addons/diskmonitor/lib/Notifier.php`
- Test: `tests/NotifierTest.php`

**Interfaces:**
- Consumes: `Rules::evaluate()/shouldNotify()` (Task 2), level strings `'warning'|'critical'`
- Produces: `DiskMonitor\Notifier::decide(?array $event, string $newLevel, int $cooldownH, string $now): string` (`'send'|'skip_cooldown'|'no_change'`); `::buildCustomVars(array $account, ?float $pct, array $customer, string $cpanelUrl): array`. `$event` = `null` (belum ada) atau `['level'=>..., 'status'=>..., 'last_sent_at'=>...]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Notifier;

final class NotifierTest extends TestCase {
    public function testDecide(): void {
        $this->assertSame('send', Notifier::decide(null, 'warning', 24, '2026-10-14 10:00:00'));
        $this->assertSame('no_change', Notifier::decide(null, 'none', 24, '2026-10-14 10:00:00'));
        $open = ['level' => 'warning', 'status' => 'open', 'last_sent_at' => '2026-10-14 09:00:00'];
        $this->assertSame('skip_cooldown', Notifier::decide($open, 'warning', 24, '2026-10-14 10:00:00'));
        $this->assertSame('send', Notifier::decide($open, 'critical', 24, '2026-10-14 10:00:00'));
        $old = ['level' => 'warning', 'status' => 'open', 'last_sent_at' => '2026-10-13 09:00:00'];
        $this->assertSame('send', Notifier::decide($old, 'warning', 24, '2026-10-14 10:00:00'));
        $resolved = ['level' => 'warning', 'status' => 'resolved', 'last_sent_at' => '2026-10-13 09:00:00'];
        $this->assertSame('send', Notifier::decide($resolved, 'warning', 24, '2026-10-14 10:00:00'));
    }

    public function testCustomVars(): void {
        $v = Notifier::buildCustomVars(
            ['domain' => 'toko.com', 'diskused_mb' => 920.5, 'quota_mb' => 1000.0],
            92.1,
            ['firstname' => 'Budi'],
            'https://srv1.example.com:2083'
        );
        $this->assertSame('Budi', $v['client_name']);
        $this->assertSame('toko.com', $v['domain']);
        $this->assertSame('92.1', $v['pct']);
        $this->assertSame('https://srv1.example.com:2083', $v['cpanel_url']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/NotifierTest.php`
Expected: FAIL with "Class 'DiskMonitor\Notifier' not found".

- [ ] **Step 3: Write minimal implementation**

```php
<?php
namespace DiskMonitor;

class Notifier {
    public static function decide(?array $event, string $newLevel, int $cooldownH, string $now): string {
        if ($newLevel !== 'warning' && $newLevel !== 'critical') {
            return 'no_change';
        }
        if ($event === null || ($event['status'] ?? 'open') !== 'open') {
            return 'send';
        }
        $escalated = ($event['level'] ?? '') === 'warning' && $newLevel === 'critical';
        if (Rules::shouldNotify($event['last_sent_at'] ?? null, $cooldownH, $escalated, $now)) {
            return 'send';
        }
        return 'skip_cooldown';
    }

    public static function buildCustomVars(array $account, ?float $pct, array $customer, string $cpanelUrl): array {
        return [
            'client_name' => (string) ($customer['firstname'] ?? ''),
            'domain' => (string) ($account['domain'] ?? ''),
            'pct' => $pct === null ? '-' : number_format($pct, 1),
            'diskused' => number_format((float) ($account['diskused_mb'] ?? 0), 1),
            'quota' => number_format((float) ($account['quota_mb'] ?? 0), 1),
            'cpanel_url' => $cpanelUrl,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/`
Expected: `OK` — semua test hijau.

- [ ] **Step 5: Commit**

```bash
git add modules/addons/diskmonitor/lib/Notifier.php tests/NotifierTest.php tests/bootstrap.php
git commit -m "feat: add notifier decide logic and email customvars"
```

---

### Task 6: WHMCS wiring — Runner, cron, ingest, hooks, admin UI

**Files:**
- Create: `modules/addons/diskmonitor/lib/Runner.php`, `modules/addons/diskmonitor/cron.php`, `modules/addons/diskmonitor/ingest.php`, `modules/addons/diskmonitor/hooks.php`, `modules/addons/diskmonitor/templates/overview.tpl`, `modules/addons/diskmonitor/lang/indonesian.php`, `modules/addons/diskmonitor/lang/english.php`
- Modify: `modules/addons/diskmonitor/diskmonitor.php` (ganti `_activate` PDO → Capsule, `_output` render overview + aksi)
- Test: `php -l` semua file + staging checklist manual (tidak ada PHPUnit — butuh WHMCS hidup)

**Interfaces:**
- Consumes: `Rules`, `Collector`, `Resolver`, `Notifier` (Tasks 2–5); `diskmonitor_validate_settings()` (Task 1)
- Produces: `DiskMonitor\Runner::runCheck($db, callable $mailer, callable $whmFactory, array $settings, string $now): array` summary `['servers_ok','servers_failed','emails_sent','events_open']`; hook `diskmonitor_daily()`; endpoint POST `ingest.php` `{server_key, accounts[]}` → `{ok, accepted}`.

- [ ] **Step 1: Write `lib/Runner.php` (orkestrasi murni-DB, tanpa Capsule langsung — terima `$db` adapter minimal)**

Adapter `$db` yang dibutuhkan (interface implisit, didokumentasikan di sini — implementasi Capsule di `cron.php`):
`$db->getServers(): array` (rows `tblservers` + `id,hostname,ipaddress,username,accesshash,disabled,type`);
`$db->getHostingRows(): array` (join hosting+clients);
`$db->upsertCache(array $row, string $source, string $now): void`;
`$db->findOpenEvent(int $serverId, string $user): ?array`;
`$db->openEvent(...)`, `$db->updateEvent(...)`, `$db->resolveEvent(...)`, `$db->insertLog(...)`.

```php
<?php
namespace DiskMonitor;

class Runner {
    public static function runCheck($db, callable $mailer, callable $whmFactory, array $settings, string $now): array {
        $warn = (float) $settings['warn_pct'];
        $crit = (float) $settings['crit_pct'];
        $cool = (int) $settings['cooldown_h'];
        $reso = (float) $settings['resolve_below'];
        $summary = ['servers_ok' => 0, 'servers_failed' => 0, 'emails_sent' => 0, 'events_open' => 0];
        $hostingRows = $db->getHostingRows();

        foreach ($db->getServers() as $server) {
            try {
                $accounts = Collector::pollServer($server, $whmFactory($server));
                $summary['servers_ok']++;
            } catch (\Throwable $e) {
                $summary['servers_failed']++;
                $db->insertLog(['event_id' => null, 'server_id' => (int) $server['id'], 'user' => '', 'email_to' => '', 'template' => '', 'result' => 'failed', 'message' => substr($e->getMessage(), 0, 500), 'created_at' => $now]);
                continue;
            }
            foreach ($accounts as $acct) {
                if (!empty($acct['unlimited']) || $acct['pct'] === null) {
                    $db->upsertCache($acct, 'api', $now);
                    continue;
                }
                $level = Rules::evaluate((float) $acct['pct'], !empty($acct['over_quota']), $warn, $crit);
                $db->upsertCache($acct, 'api', $now);
                if ($level === 'none') {
                    if ((float) $acct['pct'] < $reso) {
                        $db->resolveEvent((int) $server['id'], (string) $acct['user'], $now);
                    }
                    continue;
                }
                $event = $db->findOpenEvent((int) $server['id'], (string) $acct['user']);
                $decision = Notifier::decide($event, $level, $cool, $now);
                if ($event === null) {
                    $eventId = $db->openEvent((int) $server['id'], (string) $acct['user'], (string) $acct['domain'], $level, $now);
                    $event = ['id' => $eventId, 'level' => $level, 'status' => 'open', 'last_sent_at' => null];
                    $decision = Notifier::decide(null, $level, $cool, $now);
                }
                if ($decision === 'no_change') {
                    continue;
                }
                if ($decision === 'skip_cooldown') {
                    $db->insertLog(['event_id' => $event['id'] ?? null, 'server_id' => (int) $server['id'], 'user' => (string) $acct['user'], 'email_to' => '', 'template' => '', 'result' => 'skipped_cooldown', 'message' => $level, 'created_at' => $now]);
                    continue;
                }
                $customer = Resolver::resolve(['user' => (string) $acct['user'], 'domain' => (string) $acct['domain'], 'server_id' => (int) $server['id']], $hostingRows);
                if ($customer === null) {
                    $db->updateEvent((int) $event['id'], ['status' => 'unmapped', 'last_seen' => $now]);
                    $db->insertLog(['event_id' => (int) $event['id'], 'server_id' => (int) $server['id'], 'user' => (string) $acct['user'], 'email_to' => '', 'template' => '', 'result' => 'unmapped', 'message' => (string) $acct['domain'], 'created_at' => $now]);
                    continue;
                }
                $vars = Notifier::buildCustomVars($acct, $acct['pct'], $customer, 'https://' . $server['hostname'] . ':2083');
                $sent = $mailer((int) $customer['userid'], (string) $settings['email_template'], $vars);
                if ($sent) {
                    $db->updateEvent((int) $event['id'], ['level' => $level, 'last_sent_at' => $now, 'last_seen' => $now, 'send_count+1' => true]);
                    $db->insertLog(['event_id' => (int) $event['id'], 'server_id' => (int) $server['id'], 'user' => (string) $acct['user'], 'email_to' => $customer['email'], 'template' => (string) $settings['email_template'], 'result' => 'sent', 'message' => $level . ' ' . $vars['pct'] . '%', 'created_at' => $now]);
                    $summary['emails_sent']++;
                } else {
                    $db->insertLog(['event_id' => (int) $event['id'], 'server_id' => (int) $server['id'], 'user' => (string) $acct['user'], 'email_to' => $customer['email'], 'template' => (string) $settings['email_template'], 'result' => 'failed', 'message' => 'mailer returned false', 'created_at' => $now]);
                }
            }
        }
        return $summary;
    }
}
```

- [ ] **Step 2: Write `cron.php`, `ingest.php`, `hooks.php`, lang files, template**

`cron.php`:
```php
<?php
// Sys cron: */15 * * * * php -q /var/www/whmcs/modules/addons/diskmonitor/cron.php key=AGENT_KEY_GLOBAL_ANDA
if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') === '') {
    http_response_code(403);
    exit('forbidden');
}
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/diskmonitor.php';
foreach (['lib/Rules.php','lib/QuotaParser.php','lib/WhmClient.php','lib/Collector.php','lib/Resolver.php','lib/Notifier.php','lib/Runner.php'] as $f) {
    require_once __DIR__ . '/' . $f;
}
use WHMCS\Database\Capsule;
use DiskMonitor\Runner;
use DiskMonitor\WhmClient;

$settings = diskmonitor_validate_settings([
    'warn_pct' => Capsule::table('tbladdonmodules')->where('module','diskmonitor')->where('setting','warn_pct')->value('value'),
    'crit_pct' => Capsule::table('tbladdonmodules')->where('module','diskmonitor')->where('setting','crit_pct')->value('value'),
    'cooldown_h' => Capsule::table('tbladdonmodules')->where('module','diskmonitor')->where('setting','cooldown_h')->value('value'),
    'resolve_below' => Capsule::table('tbladdonmodules')->where('module','diskmonitor')->where('setting','resolve_below')->value('value'),
    'email_template' => Capsule::table('tbladdonmodules')->where('module','diskmonitor')->where('setting','email_template')->value('value'),
]);
$expectKey = (string) Capsule::table('tbladdonmodules')->where('module','diskmonitor')->where('setting','agent_key_global')->value('value');
$givenKey = isset($argv) && isset($argv[1]) ? preg_replace('/^key=/', '', $argv[1]) : (string) ($_GET['key'] ?? '');
if ($expectKey === '' || !hash_equals($expectKey, $givenKey)) {
    http_response_code(403);
    exit('forbidden');
}
require_once __DIR__ . '/lib/DbAdapter.php'; // dibuat di step yang sama di bawah
$now = date('Y-m-d H:i:s');
$summary = Runner::runCheck(new \DiskMonitor\DbAdapter(), function ($userid, $template, $vars) {
    $r = localAPI('SendEmail', ['messagename' => $template, 'id' => $userid, 'customvars' => $vars], 'admin');
    return isset($r['result']) && $r['result'] === 'success';
}, function ($server) {
    $host = 'https://' . (!empty($server['hostname']) ? $server['hostname'] : $server['ipaddress']);
    return new WhmClient($host, (string) $server['username'], (string) $server['accesshash']);
}, $settings, $now);
echo json_encode($summary) . "\n";
```

`lib/DbAdapter.php` (Capsule implementation of the adapter used above):
```php
<?php
namespace DiskMonitor;

use WHMCS\Database\Capsule;

class DbAdapter {
    public function getServers(): array {
        return Capsule::table('tblservers')->where('type', 'cpanel')->where('disabled', 0)->get()->map(function ($r) {
            return (array) $r;
        })->all();
    }

    public function getHostingRows(): array {
        return Capsule::table('tblhosting as h')
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->select(['h.server', 'h.username', 'h.domain', 'h.userid', 'c.email', 'c.firstname'])
            ->get()->map(function ($r) {
                return (array) $r;
            })->all();
    }

    public function upsertCache(array $row, string $source, string $now): void {
        Capsule::table('mod_diskmonitor_cache')->updateOrInsert(
            ['server_id' => (int) ($row['server_id'] ?? 0), 'user' => (string) $row['user']],
            ['domain' => (string) ($row['domain'] ?? ''), 'diskused_mb' => (float) ($row['diskused_mb'] ?? 0), 'quota_mb' => (float) ($row['quota_mb'] ?? 0), 'pct' => $row['pct'] === null ? 0 : (float) $row['pct'], 'unlimited' => !empty($row['unlimited']) ? 1 : 0, 'source' => $source, 'updated_at' => $now]
        );
    }

    public function findOpenEvent(int $serverId, string $user): ?array {
        $r = Capsule::table('mod_diskmonitor_events')->where('server_id', $serverId)->where('user', $user)->where('status', 'open')->first();
        return $r ? (array) $r : null;
    }

    public function openEvent(int $serverId, string $user, string $domain, string $level, string $now): int {
        return (int) Capsule::table('mod_diskmonitor_events')->insertGetId(['server_id' => $serverId, 'user' => $user, 'domain' => $domain, 'level' => $level, 'status' => 'open', 'first_seen' => $now, 'last_seen' => $now]);
    }

    public function updateEvent(int $id, array $fields): void {
        $upd = [];
        foreach (['level', 'status', 'last_seen', 'last_sent_at'] as $k) {
            if (array_key_exists($k, $fields)) {
                $upd[$k] = $fields[$k];
            }
        }
        if (isset($fields['send_count+1'])) {
            Capsule::table('mod_diskmonitor_events')->where('id', $id)->increment('send_count');
        }
        if ($upd) {
            Capsule::table('mod_diskmonitor_events')->where('id', $id)->update($upd);
        }
    }

    public function resolveEvent(int $serverId, string $user, string $now): void {
        Capsule::table('mod_diskmonitor_events')->where('server_id', $serverId)->where('user', $user)->where('status', 'open')->update(['status' => 'resolved', 'resolved_at' => $now]);
    }

    public function insertLog(array $log): void {
        Capsule::table('mod_diskmonitor_log')->insert($log);
    }
}
```

`ingest.php`:
```php
<?php
// POST JSON {server_key, hostname, accounts:[{user,domain,diskused_mb,quota_mb,pct}]}
require_once __DIR__ . '/../../../init.php';
use WHMCS\Database\Capsule;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'method not allowed']));
}
$raw = file_get_contents('php://input');
if (strlen((string) $raw) > 2 * 1024 * 1024) {
    http_response_code(413);
    exit(json_encode(['error' => 'payload too large']));
}
$in = json_decode((string) $raw, true);
$expectKey = (string) Capsule::table('tbladdonmodules')->where('module', 'diskmonitor')->where('setting', 'agent_key_global')->value('value');
if (!is_array($in) || $expectKey === '' || !hash_equals($expectKey, (string) ($in['server_key'] ?? ''))) {
    http_response_code(403);
    exit(json_encode(['error' => 'forbidden']));
}
$server = Capsule::table('tblservers')->where('hostname', (string) ($in['hostname'] ?? ''))->first();
if (!$server) {
    http_response_code(404);
    exit(json_encode(['error' => 'unknown server']));
}
$now = date('Y-m-d H:i:s');
$accepted = 0;
foreach ((array) ($in['accounts'] ?? []) as $a) {
    if (!is_array($a) || empty($a['user'])) {
        continue;
    }
    Capsule::table('mod_diskmonitor_cache')->updateOrInsert(
        ['server_id' => (int) $server->id, 'user' => (string) $a['user']],
        ['domain' => (string) ($a['domain'] ?? ''), 'diskused_mb' => (float) ($a['diskused_mb'] ?? 0), 'quota_mb' => (float) ($a['quota_mb'] ?? 0), 'pct' => (float) ($a['pct'] ?? 0), 'unlimited' => 0, 'source' => 'agent', 'updated_at' => $now]
    );
    $accepted++;
}
echo json_encode(['ok' => true, 'accepted' => $accepted]);
```

`hooks.php`:
```php
<?php
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}
add_hook('DailyCronJob', 1, function () {
    try {
        \WHMCS\Database\Capsule::table('mod_diskmonitor_cache')->where('updated_at', '<', date('Y-m-d H:i:s', strtotime('-7 days')))->delete();
        \WHMCS\Database\Capsule::table('mod_diskmonitor_log')->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-90 days')))->delete();
        \WHMCS\Database\Capsule::table('mod_diskmonitor_events')->where('status', 'open')->where('last_seen', '<', date('Y-m-d H:i:s', strtotime('-7 days')))->update(['status' => 'resolved', 'resolved_at' => date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        logActivity('DiskMonitor housekeeping failed: ' . substr($e->getMessage(), 0, 300));
    }
});
```

`lang/indonesian.php` / `lang/english.php`:
```php
<?php
$_ADDONLANG['title'] = 'Monitor Disk';
$_ADDONLANG['checknow'] = 'Periksa Sekarang';
```
```php
<?php
$_ADDONLANG['title'] = 'Disk Monitor';
$_ADDONLANG['checknow'] = 'Check Now';
```

`templates/overview.tpl` (awal, tabel dirender dari `$events` yang disiapkan `_output`):
```smarty
<h2>{$title}</h2>
<p>Server OK: {$summary.servers_ok} | Gagal: {$summary.servers_failed} | Email 24 jam: {$summary.emails_sent}</p>
<table class="datatable" width="100%">
<tr><th>Server</th><th>User</th><th>Domain</th><th>%</th><th>Level</th><th>Status</th><th>Terakhir kirim</th></tr>
{foreach from=$events item=e}
<tr><td>{$e.server_id|escape}</td><td>{$e.user|escape}</td><td>{$e.domain|escape}</td><td>{$e.pct|escape}</td><td>{$e.level|escape}</td><td>{$e.status|escape}</td><td>{$e.last_sent_at|escape}</td></tr>
{/foreach}
</table>
```

- [ ] **Step 3: Update `diskmonitor.php` `_activate` → Capsule + `_output` render template**

Ganti body `diskmonitor_activate()` PDO dengan:
```php
function diskmonitor_activate() {
    $schema = \WHMCS\Database\Capsule::schema();
    if (!$schema->hasTable('mod_diskmonitor_cache')) {
        $schema->create('mod_diskmonitor_cache', function ($t) {
            $t->integer('server_id');
            $t->string('user', 64);
            $t->string('domain', 255)->default('');
            $t->decimal('diskused_mb', 12, 2)->default(0);
            $t->decimal('quota_mb', 12, 2)->default(0);
            $t->decimal('pct', 5, 1)->default(0);
            $t->boolean('unlimited')->default(false);
            $t->enum('source', ['api', 'agent'])->default('api');
            $t->dateTime('updated_at');
            $t->primary(['server_id', 'user']);
            $t->index('pct');
        });
    }
    if (!$schema->hasTable('mod_diskmonitor_events')) {
        $schema->create('mod_diskmonitor_events', function ($t) {
            $t->increments('id');
            $t->integer('server_id');
            $t->string('user', 64);
            $t->string('domain', 255)->default('');
            $t->enum('level', ['warning', 'critical']);
            $t->enum('status', ['open', 'resolved', 'unmapped'])->default('open');
            $t->dateTime('first_seen');
            $t->dateTime('last_seen');
            $t->dateTime('last_sent_at')->nullable();
            $t->integer('send_count')->default(0);
            $t->dateTime('resolved_at')->nullable();
            $t->index(['status', 'server_id']);
        });
    }
    if (!$schema->hasTable('mod_diskmonitor_log')) {
        $schema->create('mod_diskmonitor_log', function ($t) {
            $t->increments('id');
            $t->integer('event_id')->nullable();
            $t->integer('server_id');
            $t->string('user', 64);
            $t->string('email_to', 255)->default('');
            $t->string('template', 128)->default('');
            $t->enum('result', ['sent', 'skipped_cooldown', 'failed', 'unmapped']);
            $t->text('message')->nullable();
            $t->dateTime('created_at');
            $t->index('event_id');
        });
    }
    $hasTemplate = \WHMCS\Database\Capsule::table('tblemailtemplates')->where('name', 'Disk Usage Warning')->exists();
    if (!$hasTemplate) {
        throw new \Exception('Buat dulu Email Template "Disk Usage Warning" di Setup > Email Templates sebelum aktivasi.');
    }
    $key = \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'diskmonitor')->where('setting', 'agent_key_global')->value('value');
    if (!$key) {
        \WHMCS\Database\Capsule::table('tbladdonmodules')->insert(['module' => 'diskmonitor', 'setting' => 'agent_key_global', 'value' => bin2hex(random_bytes(16))]);
    }
    return ['status' => 'success', 'description' => 'Tabel dibuat. Tambahkan sys cron 15 menit (lihat README).'];
}
```

Ganti `diskmonitor_output()` dengan render overview (query events+cache, assign ke Smarty, tampilkan `overview.tpl`).

- [ ] **Step 4: Verify — lint + staging checklist**

Run: `for f in modules/addons/diskmonitor/*.php modules/addons/diskmonitor/lib/*.php; do php -l $f; done`
Expected: `No syntax errors` untuk semua file.

Staging checklist (manual, di WHMCS staging — catat hasil di PR/commit message):
1. Buat Email Template `Disk Usage Warning` (tipe Product).
2. Activate modul → tabel terbentuk, key ter-generate.
3. Tambahkan sys cron 15 mnt → jalankan `cron.php key=...` manual → JSON summary muncul.
4. Verifikasi 1 email warning + 1 critical masuk ke mailbox test.

- [ ] **Step 5: Commit**

```bash
git add modules/addons/diskmonitor/
git commit -m "feat: wire WHMCS cron, ingest, hooks, and admin overview"
```

---

### Task 7: Agent + README + end-to-end UAT

**Files:**
- Create: `agent/agent.php`, `modules/addons/diskmonitor/README.md`
- Test: staging end-to-end (mailbox test + overview)

**Interfaces:**
- Consumes: `ingest.php` contract (Task 6): POST `{server_key, hostname, accounts[]}` → `{ok, accepted}`
- Produces: working agent + install docs; UAT sign-off per acceptance criteria spec §22.

- [ ] **Step 1: Write `agent/agent.php`**

```php
<?php
// Instal: salin ke /root/diskmonitor-agent.php, isi 3 config, cron root: */5 * * * * php /root/diskmonitor-agent.php
$ingestUrl = 'https://WHMCS_ANDA/modules/addons/diskmonitor/ingest.php';
$serverKey = 'AGENT_KEY_GLOBAL_ANDA';
$hostname = trim((string) shell_exec('hostname -f'));
$stateFile = '/root/.diskmonitor-state.json';

$prev = [];
if (is_file($stateFile)) {
    $decoded = json_decode((string) file_get_contents($stateFile), true);
    if (is_array($decoded)) {
        $prev = $decoded;
    }
}
$accounts = [];
$lines = explode("\n", (string) shell_exec('repquota -a 2>/dev/null'));
foreach ($lines as $line) {
    if (!preg_match('/^(\S+)\s+--\s+(\d+)\s+(\d+)\s+(\d+)/', trim($line), $m)) {
        continue;
    }
    $user = $m[1];
    $usedKb = (float) $m[2];
    $quotaKb = (float) $m[4];
    if ($quotaKb <= 0) {
        continue;
    }
    $usedMb = round($usedKb / 1024, 2);
    $quotaMb = round($quotaKb / 1024, 2);
    $pct = round($usedMb / $quotaMb * 100, 1);
    $old = isset($prev[$user]) ? (float) $prev[$user] : -100.0;
    if (abs($pct - $old) < 1.0 && isset($prev[$user])) {
        continue;
    }
    $domain = trim((string) shell_exec('uapi --user=' . escapeshellarg($user) . ' DomainInfo_single_domain_data 2>/dev/null'));
    $accounts[] = ['user' => $user, 'domain' => $domain !== '' ? $domain : $user, 'diskused_mb' => $usedMb, 'quota_mb' => $quotaMb, 'pct' => $pct];
    $prev[$user] = $pct;
}
if ($accounts === []) {
    exit(0);
}
$ch = curl_init($ingestUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['server_key' => $serverKey, 'hostname' => $hostname, 'accounts' => $accounts])]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code >= 200 && $code < 300) {
    file_put_contents($stateFile, json_encode($prev), LOCK_EX);
} else {
    fwrite(STDERR, 'ingest failed HTTP ' . $code . ' ' . substr((string) $resp, 0, 200) . "\n");
    exit(1);
}
```

- [ ] **Step 2: Write `modules/addons/diskmonitor/README.md`** (instalasi + SOP: buat template, aktifkan modul, salin key, tambah sys cron `*/15`, deploy agent opsional, troubleshooting unmapped/failed, retensi log).

- [ ] **Step 3: Staging UAT — jalankan acceptance checklist spec §22 satu per satu**

1. Activate tanpa error. 2. Check Now 1 server pilot cocok dengan cPanel Disk Usage. 3. Akun 85–94% terima 1 warning; ≥95% terima critical. 4. Tidak ada email kedua <24 jam. 5. Turun <80% → resolved. 6. Unmapped tanpa email. Catat hasil (tanggal, server, user test) di commit message.

- [ ] **Step 4: Commit + tag**

```bash
git add agent/agent.php modules/addons/diskmonitor/README.md
git commit -m "feat: add cPanel agent and install docs; UAT: <hasil>"
git tag -a v0.1.0 -m "MVP disk monitor"
```

---

## Self-Review (dilakukan penulis plan)

1. **Spec coverage:** §3.1 poll → Task 6 Runner; threshold/cooldown/resolve → Tasks 2+5+6; email dari tblclients → Tasks 4+6; admin Check Now/Send Test → Task 6 output+checklist; auto-resolve → Runner; audit log → DbAdapter+insertLog; agent → Task 7; housekeeping → hooks. Template email + lang → Task 6. Non-goals (spam/malware/tiket) tidak ada task — sesuai spec.
2. **Placeholder scan:** tidak ada TBD/TODO/"similar to"; path `/var/www/whmcs` + `WHMCS_ANDA` adalah nilai contoh instalasi yang didokumentasikan di README (executor ganti saat deploy staging, bukan placeholder logika). Helper `void()` dari draft dihapus dan diganti versi final `pollServer` — executor pakai versi final.
3. **Type consistency:** `evaluate(float,bool,float,float): string`; `normalize(): array` dengan keys `user,domain,diskused_mb,quota_mb,pct,unlimited,over_quota` (+`server_id` dari `pollServer`); `resolve(): ?array{userid:int,email:string,firstname:string}`; `decide(?array,string,int,string): string`; `buildCustomVars(): array{client_name,domain,pct,diskused,quota,cpanel_url}`; `runCheck(): array{servers_ok,servers_failed,emails_sent,events_open}` — konsisten di semua task. `updateEvent` memakai flag `'send_count+1' => true` untuk increment — didefinisikan di Runner dan diimplementasikan di DbAdapter.
