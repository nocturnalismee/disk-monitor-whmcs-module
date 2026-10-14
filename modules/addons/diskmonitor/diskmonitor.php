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
    foreach (['mod_diskmonitor_cache', 'mod_diskmonitor_events', 'mod_diskmonitor_log'] as $table) {
        if (!$schema->hasTable($table)) {
            throw new \Exception('Aktivasi gagal: tabel ' . $table . ' tidak terbentuk.');
        }
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

function diskmonitor_deactivate() {
}

function diskmonitor_output($vars) {
    $modulelink = isset($vars['modulelink']) ? $vars['modulelink'] : 'addonmodules.php?module=diskmonitor';
    $title = isset($vars['_lang']['title']) ? $vars['_lang']['title'] : 'Disk Monitor';
    $checknow = isset($vars['_lang']['checknow']) ? $vars['_lang']['checknow'] : 'Check Now';

    $notice = '';
    if (isset($_GET['action']) && $_GET['action'] === 'checknow') {
        foreach (['lib/Rules.php', 'lib/QuotaParser.php', 'lib/WhmClient.php', 'lib/Collector.php', 'lib/Resolver.php', 'lib/Notifier.php', 'lib/Runner.php', 'lib/DbAdapter.php'] as $f) {
            require_once __DIR__ . '/' . $f;
        }
        try {
            $settings = diskmonitor_validate_settings([
                'warn_pct' => \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'diskmonitor')->where('setting', 'warn_pct')->value('value'),
                'crit_pct' => \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'diskmonitor')->where('setting', 'crit_pct')->value('value'),
                'cooldown_h' => \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'diskmonitor')->where('setting', 'cooldown_h')->value('value'),
                'resolve_below' => \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'diskmonitor')->where('setting', 'resolve_below')->value('value'),
                'email_template' => \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'diskmonitor')->where('setting', 'email_template')->value('value'),
            ]);
            $now = date('Y-m-d H:i:s');
            $summary = \DiskMonitor\Runner::runCheck(new \DiskMonitor\DbAdapter(), function ($userid, $template, $emailVars) {
                $r = localAPI('SendEmail', ['messagename' => $template, 'id' => $userid, 'customvars' => $emailVars], 'admin');
                return isset($r['result']) && $r['result'] === 'success';
            }, function ($server) {
                $host = 'https://' . (!empty($server['hostname']) ? $server['hostname'] : $server['ipaddress']);
                return new \DiskMonitor\WhmClient($host, (string) $server['username'], (string) $server['accesshash']);
            }, $settings, $now);
            $notice = '<div class="successbox">OK: ' . (int) $summary['servers_ok'] . ' | Gagal: ' . (int) $summary['servers_failed'] . ' | Email: ' . (int) $summary['emails_sent'] . '</div>';
        } catch (\Throwable $e) {
            $notice = '<div class="errorbox">Check gagal: ' . htmlspecialchars(substr($e->getMessage(), 0, 300)) . '</div>';
        }
    }

    $events = \WHMCS\Database\Capsule::table('mod_diskmonitor_events as e')
        ->leftJoin('mod_diskmonitor_cache as c', function ($j) {
            $j->on('c.server_id', '=', 'e.server_id')->on('c.user', '=', 'e.user');
        })
        ->whereIn('e.status', ['open', 'unmapped'])
        ->orderBy('e.id', 'desc')->limit(50)
        ->select(['e.server_id', 'e.user', 'e.domain', 'c.pct', 'e.level', 'e.status', 'e.last_sent_at'])
        ->get()->map(function ($r) {
            return (array) $r;
        })->all();
    $sent24 = \WHMCS\Database\Capsule::table('mod_diskmonitor_log')
        ->where('result', 'sent')
        ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
        ->count();
    $summary = ['servers_ok' => 0, 'servers_failed' => 0, 'emails_sent' => (int) $sent24];

    if (isset($GLOBALS['smarty']) && is_object($GLOBALS['smarty']) && method_exists($GLOBALS['smarty'], 'assign')) {
        $smarty = $GLOBALS['smarty'];
        $smarty->assign('title', $title);
        $smarty->assign('checknow', $checknow);
        $smarty->assign('modulelink', $modulelink);
        $smarty->assign('notice', $notice);
        $smarty->assign('events', $events);
        $smarty->assign('summary', $summary);
        $smarty->display(__DIR__ . '/templates/overview.tpl');
        return;
    }
    echo $notice;
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    echo '<p><a href="' . htmlspecialchars($modulelink) . '&action=checknow" class="btn btn-primary">' . htmlspecialchars($checknow) . '</a></p>';
    echo '<p>Server OK: ' . (int) $summary['servers_ok'] . ' | Gagal: ' . (int) $summary['servers_failed'] . ' | Email 24 jam: ' . (int) $summary['emails_sent'] . '</p>';
    echo '<table class="datatable" width="100%"><tr><th>Server</th><th>User</th><th>Domain</th><th>%</th><th>Level</th><th>Status</th><th>Terakhir kirim</th></tr>';
    foreach ($events as $e) {
        echo '<tr><td>' . htmlspecialchars((string) $e['server_id']) . '</td><td>' . htmlspecialchars((string) $e['user']) . '</td><td>' . htmlspecialchars((string) $e['domain']) . '</td><td>' . htmlspecialchars((string) ($e['pct'] ?? '')) . '</td><td>' . htmlspecialchars((string) $e['level']) . '</td><td>' . htmlspecialchars((string) $e['status']) . '</td><td>' . htmlspecialchars((string) ($e['last_sent_at'] ?? '')) . '</td></tr>';
    }
    echo '</table>';
}
