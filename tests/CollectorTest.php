<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Collector;
use DiskMonitor\WhmClient;

final class PagedWhmClient extends WhmClient {
    public $calls = [];
    private $pages;
    private $summary;
    public function __construct(array $pages, array $summary = []) {
        parent::__construct('https://srv1.example.com', 'root', 'x');
        $this->pages = $pages;
        $this->summary = $summary;
    }
    public function request(string $fn, array $params = [], int $retries = 2): array {
        $this->calls[] = [$fn, $params];
        if ($fn === 'listaccts') {
            $limit = (int) ($params['limit'] ?? 1000);
            $offset = (int) ($params['offset'] ?? 0);
            return ['data' => ['acct' => array_slice($this->pages, $offset, $limit)]];
        }
        return [];
    }
    public function accountSummary(string $user): array {
        return $this->summary;
    }
}

final class CollectorTest extends TestCase {
    public function testNormalize(): void {
        $n = Collector::normalize(['user' => 'toko', 'domain' => 'toko.com', 'diskused' => '920M', 'disklimit' => '1G']);
        $this->assertSame('toko', $n['user']);
        $this->assertSame(920.0, $n['diskused_mb']);
        $this->assertSame(1024.0, $n['quota_mb']);
        $this->assertSame(89.8, $n['pct']);
        $this->assertFalse($n['unlimited']);
        $this->assertFalse($n['over_quota']);

        $u = Collector::normalize(['user' => 'bebas', 'domain' => 'bebas.com', 'diskused' => '500M', 'disklimit' => 'unlimited']);
        $this->assertTrue($u['unlimited']);
        $this->assertNull($u['pct']);
    }

    public function testListAccountsPaginates(): void {
        $rows = [];
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = ['user' => 'u' . $i, 'domain' => 'u' . $i . '.com', 'diskused' => '100M', 'disklimit' => '1000M'];
        }
        $client = new PagedWhmClient($rows);
        $this->assertCount(2500, $client->listAccounts());
        $this->assertCount(3, $client->calls);
        $this->assertSame(0, $client->calls[0][1]['offset']);
        $this->assertSame(2000, $client->calls[2][1]['offset']);
    }

    public function testPollServerFallsBackToAccountSummary(): void {
        $client = new PagedWhmClient(
            [['user' => 'tipis', 'domain' => 'tipis.com', 'diskused' => '', 'disklimit' => '']],
            [['domain' => 'tipis.com', 'diskused' => '920', 'disklimit' => '1000']]
        );
        $rows = Collector::pollServer(['id' => 1], $client);
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['unlimited']);
        $this->assertSame(92.0, $rows[0]['pct']);
    }

    public function testPollServerKeepsUnlimitedWhenFallbackEmpty(): void {
        $client = new PagedWhmClient(
            [['user' => 'bebas', 'domain' => 'bebas.com', 'diskused' => '500M', 'disklimit' => 'unlimited']]
        );
        $rows = Collector::pollServer(['id' => 1], $client);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['unlimited']);
        $this->assertNull($rows[0]['pct']);
    }
}
