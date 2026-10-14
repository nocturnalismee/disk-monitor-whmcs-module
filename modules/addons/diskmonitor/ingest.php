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
