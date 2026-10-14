<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Collector;

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
}
