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
