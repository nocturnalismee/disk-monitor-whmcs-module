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
