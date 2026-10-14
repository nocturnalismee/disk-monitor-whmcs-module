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
if (!is_array($in)) {
    http_response_code(400);
    exit(json_encode(['error' => 'bad request']));
}
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$throttleKey = md5($clientIp . '|' . (string) ($in['hostname'] ?? ''));
$throttleFile = rtrim((string) sys_get_temp_dir(), '/\\') . '/diskmonitor-ingest-' . $throttleKey . '.json';
$last = 0;
if (is_file($throttleFile)) {
    $prev = json_decode((string) @file_get_contents($throttleFile), true);
    if (is_array($prev) && isset($prev['at'])) {
        $last = (int) $prev['at'];
    }
}
if (time() - $last < 60) {
    http_response_code(429);
    exit(json_encode(['error' => 'too many requests']));
}
@file_put_contents($throttleFile, json_encode(['at' => time()]), LOCK_EX);
$expectKey = (string) Capsule::table('tbladdonmodules')->where('module', 'diskmonitor')->where('setting', 'agent_key_global')->value('value');
if ($expectKey === '' || !hash_equals($expectKey, (string) ($in['server_key'] ?? ''))) {
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
