<?php
namespace DiskMonitor;

class Resolver {
    public static function resolve(array $account, array $hostingRows): ?array {
        $user = strtolower(trim((string) ($account['user'] ?? '')));
        $domain = strtolower(trim((string) ($account['domain'] ?? '')));
        $serverId = (int) ($account['server_id'] ?? 0);

        $pick = function (array $row): array {
            return [
                'userid' => (int) $row['userid'],
                'email' => (string) $row['email'],
                'firstname' => (string) ($row['firstname'] ?? ''),
            ];
        };

        foreach ($hostingRows as $row) {
            if ((int) $row['server'] === $serverId && strtolower((string) $row['username']) === $user) {
                return $pick($row);
            }
        }
        if ($domain !== '') {
            foreach ($hostingRows as $row) {
                if (strtolower((string) $row['domain']) === $domain) {
                    return $pick($row);
                }
            }
        }
        foreach ($hostingRows as $row) {
            if (strtolower((string) $row['username']) === $user) {
                return $pick($row);
            }
        }
        return null;
    }
}
