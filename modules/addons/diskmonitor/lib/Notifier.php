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
