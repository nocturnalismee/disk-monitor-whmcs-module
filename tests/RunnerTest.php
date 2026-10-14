<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Runner;
use DiskMonitor\WhmClient;

final class FakeWhmClient extends WhmClient {
    private $accts;
    public function __construct(array $accts) {
        parent::__construct('https://srv1.example.com', 'root', 'x');
        $this->accts = $accts;
    }
    public function listAccounts(): array {
        return $this->accts;
    }
    public function accountSummary(string $user): array {
        return [];
    }
}

final class FakeDb {
    public $servers;
    public $hostingRows;
    public $cache = [];
    public $events = [];
    public $logs = [];
    public $agentCache = [];
    private $nextId = 100;

    public function __construct(array $servers, array $hostingRows, array $events = []) {
        $this->servers = $servers;
        $this->hostingRows = $hostingRows;
        $this->events = $events;
    }
    public function getServers(): array {
        return $this->servers;
    }
    public function getHostingRows(): array {
        return $this->hostingRows;
    }
    public function upsertCache(array $row, string $source, string $now): void {
        $this->cache[(int) $row['server_id'] . ':' . (string) $row['user']] = [$row, $source, $now];
    }
    public function findOpenEvent(int $serverId, string $user): ?array {
        foreach ($this->events as $e) {
            if ((int) $e['server_id'] === $serverId && (string) $e['user'] === $user && $e['status'] === 'open') {
                return $e;
            }
        }
        return null;
    }
    public function findUnmappedEvent(int $serverId, string $user): ?array {
        foreach ($this->events as $e) {
            if ((int) $e['server_id'] === $serverId && (string) $e['user'] === $user && $e['status'] === 'unmapped') {
                return $e;
            }
        }
        return null;
    }
    public function getFreshAgentCache(int $serverId, string $now, int $maxAgeMin = 10): array {
        $out = [];
        foreach ($this->agentCache as $r) {
            if ((int) ($r['server_id'] ?? 0) !== $serverId) {
                continue;
            }
            if (strtotime($now) - strtotime((string) ($r['updated_at'] ?? $now)) > $maxAgeMin * 60) {
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }
    public function openEvent(int $serverId, string $user, string $domain, string $level, string $now): int {
        $id = $this->nextId++;
        $this->events[$id] = ['id' => $id, 'server_id' => $serverId, 'user' => $user, 'domain' => $domain, 'level' => $level, 'status' => 'open', 'first_seen' => $now, 'last_seen' => $now, 'last_sent_at' => null, 'send_count' => 0];
        return $id;
    }
    public function updateEvent(int $id, array $fields): void {
        foreach (['level', 'status', 'last_seen', 'last_sent_at'] as $k) {
            if (array_key_exists($k, $fields)) {
                $this->events[$id][$k] = $fields[$k];
            }
        }
        if (isset($fields['send_count+1'])) {
            $this->events[$id]['send_count']++;
        }
    }
    public function resolveEvent(int $serverId, string $user, string $now): void {
        foreach ($this->events as $id => $e) {
            if ((int) $e['server_id'] === $serverId && (string) $e['user'] === $user && $e['status'] === 'open') {
                $this->events[$id]['status'] = 'resolved';
                $this->events[$id]['resolved_at'] = $now;
            }
        }
    }
    public function insertLog(array $log): void {
        $this->logs[] = $log;
    }
}

final class RunnerTest extends TestCase {
    private function fixtures(): array {
        $servers = [
            ['id' => 1, 'hostname' => 'srv1.example.com', 'ipaddress' => '1.2.3.4', 'username' => 'root', 'accesshash' => 'x', 'disabled' => 0, 'type' => 'cpanel'],
            ['id' => 2, 'hostname' => 'down.example.com', 'ipaddress' => '5.6.7.8', 'username' => 'root', 'accesshash' => 'x', 'disabled' => 0, 'type' => 'cpanel'],
        ];
        $hosting = [
            ['server' => 1, 'username' => 'alice', 'domain' => 'alice.com', 'userid' => 11, 'email' => 'alice@mail.com', 'firstname' => 'Alice'],
            ['server' => 1, 'username' => 'bob', 'domain' => 'bob.com', 'userid' => 12, 'email' => 'bob@mail.com', 'firstname' => 'Bob'],
            ['server' => 1, 'username' => 'carol', 'domain' => 'carol.com', 'userid' => 13, 'email' => 'carol@mail.com', 'firstname' => 'Carol'],
        ];
        $events = [99 => ['id' => 99, 'server_id' => 1, 'user' => 'carol', 'domain' => 'carol.com', 'level' => 'warning', 'status' => 'open', 'first_seen' => '2026-10-13 10:00:00', 'last_seen' => '2026-10-13 10:00:00', 'last_sent_at' => null, 'send_count' => 0]];
        $accounts = [
            ['user' => 'alice', 'domain' => 'alice.com', 'diskused' => '920M', 'disklimit' => '1000M'],
            ['user' => 'bob', 'domain' => 'bob.com', 'diskused' => '970M', 'disklimit' => '1000M'],
            ['user' => 'carol', 'domain' => 'carol.com', 'diskused' => '500M', 'disklimit' => '1000M'],
            ['user' => 'dave', 'domain' => 'dave.com', 'diskused' => '100M', 'disklimit' => 'unlimited'],
            ['user' => 'ghost', 'domain' => 'ghost.com', 'diskused' => '900M', 'disklimit' => '1000M'],
        ];
        return [$servers, $hosting, $events, $accounts];
    }

    public function testRunCheck(): void {
        list($servers, $hosting, $events, $accounts) = $this->fixtures();
        $db = new FakeDb($servers, $hosting, $events);
        $sent = [];
        $mailer = function ($userid, $template, $vars) use (&$sent) {
            $sent[] = [$userid, $template, $vars];
            return true;
        };
        $factory = function ($server) use ($accounts) {
            if ((int) $server['id'] === 2) {
                throw new \RuntimeException('connection refused');
            }
            return new FakeWhmClient($accounts);
        };
        $settings = ['warn_pct' => 85, 'crit_pct' => 95, 'cooldown_h' => 24, 'resolve_below' => 80, 'email_template' => 'Disk Usage Warning'];
        $now = '2026-10-14 10:00:00';

        $summary = Runner::runCheck($db, $mailer, $factory, $settings, $now);
        $this->assertSame(['servers_ok' => 1, 'servers_failed' => 1, 'emails_sent' => 2, 'events_open' => 3], $summary);
        $this->assertCount(2, $sent);
        $this->assertSame(11, $sent[0][0]);
        $this->assertSame(12, $sent[1][0]);
        $this->assertCount(5, $db->cache);
        $this->assertSame('resolved', $db->events[99]['status']);
        $results = array_column($db->logs, 'result');
        sort($results);
        $this->assertSame(['failed', 'sent', 'sent', 'unmapped'], $results);

        // Second run at same time: cooldown skips resend, no new mail.
        $eventCountBefore = count($db->events);
        $summary2 = Runner::runCheck($db, $mailer, $factory, $settings, $now);
        $this->assertSame(0, $summary2['emails_sent']);
        $this->assertSame(1, $summary2['servers_ok']);
        $this->assertSame(2, $summary2['events_open']);
        $this->assertCount(2, $sent);
        // F3: no new event opened for the unmapped ghost account.
        $this->assertCount($eventCountBefore, $db->events);
        // F4: cooldown skip still touches last_seen/level.
        foreach ($db->events as $e) {
            if (in_array($e['user'], ['alice', 'bob'], true)) {
                $this->assertSame($now, $e['last_seen']);
            }
        }
    }

    public function testAgentCacheShortCircuit(): void {
        list($servers, $hosting, $events, $accounts) = $this->fixtures();
        $db = new FakeDb([$servers[0]], $hosting, $events);
        $db->agentCache[] = ['server_id' => 1, 'user' => 'alice', 'domain' => 'alice.com', 'diskused_mb' => 920.0, 'quota_mb' => 1000.0, 'pct' => 92.0, 'unlimited' => 0, 'updated_at' => '2026-10-14 09:55:00'];
        $sent = [];
        $mailer = function ($userid, $template, $vars) use (&$sent) {
            $sent[] = [$userid, $template, $vars];
            return true;
        };
        $polled = 0;
        $factory = function ($server) use ($accounts, &$polled) {
            $polled++;
            return new FakeWhmClient($accounts);
        };
        $settings = ['warn_pct' => 85, 'crit_pct' => 95, 'cooldown_h' => 24, 'resolve_below' => 80, 'email_template' => 'Disk Usage Warning'];
        $summary = Runner::runCheck($db, $mailer, $factory, $settings, '2026-10-14 10:00:00');
        // Only the fresh agent row was processed (alice mailed once), WHM factory result unused for accounts.
        $this->assertSame(1, $summary['servers_ok']);
        $this->assertCount(1, $sent);
        $this->assertSame(11, $sent[0][0]);
        $this->assertSame(1, $summary['events_open']);
    }

    public function testStaleAgentCacheFallsBackToPoll(): void {
        list($servers, $hosting, $events, $accounts) = $this->fixtures();
        $db = new FakeDb([$servers[0]], $hosting, $events);
        $db->agentCache[] = ['server_id' => 1, 'user' => 'alice', 'domain' => 'alice.com', 'diskused_mb' => 920.0, 'quota_mb' => 1000.0, 'pct' => 92.0, 'unlimited' => 0, 'updated_at' => '2026-10-14 09:00:00'];
        $sent = [];
        $mailer = function ($userid, $template, $vars) use (&$sent) {
            $sent[] = [$userid, $template, $vars];
            return true;
        };
        $factory = function ($server) use ($accounts) {
            return new FakeWhmClient($accounts);
        };
        $settings = ['warn_pct' => 85, 'crit_pct' => 95, 'cooldown_h' => 24, 'resolve_below' => 80, 'email_template' => 'Disk Usage Warning'];
        $summary = Runner::runCheck($db, $mailer, $factory, $settings, '2026-10-14 10:00:00');
        $this->assertCount(2, $sent);
        $this->assertSame(3, $summary['events_open']);
    }
}
