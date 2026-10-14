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
