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
