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
