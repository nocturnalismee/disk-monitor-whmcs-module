<?php
namespace DiskMonitor;

class Collector {
    public static function normalize(array $rawAcct): array {
        $user = (string) ($rawAcct['user'] ?? '');
        $domain = (string) ($rawAcct['domain'] ?? '');
        $used = QuotaParser::toMb((string) ($rawAcct['diskused'] ?? ''));
        $quota = QuotaParser::toMb((string) ($rawAcct['disklimit'] ?? ''));
        $unlimited = $quota === null;
        return [
            'user' => $user,
            'domain' => $domain,
            'diskused_mb' => $used ?? 0.0,
            'quota_mb' => $quota ?? 0.0,
            'pct' => $unlimited ? null : QuotaParser::pct($used, $quota),
            'unlimited' => $unlimited,
            'over_quota' => !$unlimited && $used !== null && $quota !== null && $used >= $quota,
        ];
    }

    public static function pollServer(array $server, WhmClient $client): array {
        $out = [];
        foreach ($client->listAccounts() as $acct) {
            $row = self::normalize(is_array($acct) ? $acct : []);
            $row['server_id'] = (int) ($server['id'] ?? 0);
            $out[] = $row;
        }
        return $out;
    }
}
