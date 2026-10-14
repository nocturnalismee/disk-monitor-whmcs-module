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

    public function findUnmappedEvent(int $serverId, string $user): ?array {
        $r = Capsule::table('mod_diskmonitor_events')->where('server_id', $serverId)->where('user', $user)->where('status', 'unmapped')->first();
        return $r ? (array) $r : null;
    }

    public function getFreshAgentCache(int $serverId, string $now, int $maxAgeMin = 10): array {
        $cutoff = date('Y-m-d H:i:s', strtotime($now) - $maxAgeMin * 60);
        return Capsule::table('mod_diskmonitor_cache')
            ->where('server_id', $serverId)
            ->where('source', 'agent')
            ->where('updated_at', '>=', $cutoff)
            ->get()->map(function ($r) {
                return (array) $r;
            })->all();
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
