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
            if (($row['unlimited'] || $row['pct'] === null) && $row['user'] !== '') {
                $fb = self::fallbackViaAccountSummary($client, $row['user']);
                if ($fb !== null) {
                    $row = $fb;
                }
            }
            $row['server_id'] = (int) ($server['id'] ?? 0);
            $out[] = $row;
        }
        return $out;
    }

    private static function fallbackViaAccountSummary(WhmClient $client, string $user): ?array {
        try {
            $summary = $client->accountSummary($user);
        } catch (\Throwable $e) {
            return null;
        }
        if (empty($summary)) {
            return null;
        }
        $acct = isset($summary[0]) && is_array($summary[0]) ? $summary[0] : $summary;
        $raw = [
            'user' => $user,
            'domain' => isset($acct['domain']) ? (string) $acct['domain'] : '',
            'diskused' => self::summaryField($acct, ['diskused', 'disk_used', 'diskused_mb']),
            'disklimit' => self::summaryField($acct, ['disklimit', 'disk_limit', 'quota', 'quota_mb']),
        ];
        $row = self::normalize($raw);
        if ($row['unlimited'] || $row['pct'] === null) {
            return null;
        }
        return $row;
    }

    private static function summaryField(array $acct, array $keys): string {
        foreach ($keys as $k) {
            if (isset($acct[$k]) && $acct[$k] !== '' && $acct[$k] !== null) {
                return (string) $acct[$k];
            }
        }
        return '';
    }
}
