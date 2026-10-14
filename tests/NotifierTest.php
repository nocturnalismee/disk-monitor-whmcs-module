<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Notifier;

final class NotifierTest extends TestCase {
    public function testDecide(): void {
        $this->assertSame('send', Notifier::decide(null, 'warning', 24, '2026-10-14 10:00:00'));
        $this->assertSame('no_change', Notifier::decide(null, 'none', 24, '2026-10-14 10:00:00'));
        $open = ['level' => 'warning', 'status' => 'open', 'last_sent_at' => '2026-10-14 09:00:00'];
        $this->assertSame('skip_cooldown', Notifier::decide($open, 'warning', 24, '2026-10-14 10:00:00'));
        $this->assertSame('send', Notifier::decide($open, 'critical', 24, '2026-10-14 10:00:00'));
        $old = ['level' => 'warning', 'status' => 'open', 'last_sent_at' => '2026-10-13 09:00:00'];
        $this->assertSame('send', Notifier::decide($old, 'warning', 24, '2026-10-14 10:00:00'));
        $resolved = ['level' => 'warning', 'status' => 'resolved', 'last_sent_at' => '2026-10-13 09:00:00'];
        $this->assertSame('send', Notifier::decide($resolved, 'warning', 24, '2026-10-14 10:00:00'));
    }

    public function testCustomVars(): void {
        $v = Notifier::buildCustomVars(
            ['domain' => 'toko.com', 'diskused_mb' => 920.5, 'quota_mb' => 1000.0],
            92.1,
            ['firstname' => 'Budi'],
            'https://srv1.example.com:2083'
        );
        $this->assertSame('Budi', $v['client_name']);
        $this->assertSame('toko.com', $v['domain']);
        $this->assertSame('92.1', $v['pct']);
        $this->assertSame('https://srv1.example.com:2083', $v['cpanel_url']);
    }
}
