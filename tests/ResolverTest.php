<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Resolver;

final class ResolverTest extends TestCase {
    private $rows;

    protected function setUp(): void {
        $this->rows = [
            ['server' => 1, 'username' => 'toko', 'domain' => 'toko.com', 'userid' => 7, 'email' => 'budi@toko.com', 'firstname' => 'Budi'],
            ['server' => 2, 'username' => 'lain', 'domain' => 'lain.com', 'userid' => 9, 'email' => 'sari@lain.com', 'firstname' => 'Sari'],
        ];
    }

    public function testExactMatch(): void {
        $r = Resolver::resolve(['user' => 'toko', 'domain' => 'toko.com', 'server_id' => 1], $this->rows);
        $this->assertSame(7, $r['userid']);
        $this->assertSame('budi@toko.com', $r['email']);
    }

    public function testDomainFallback(): void {
        $r = Resolver::resolve(['user' => 'toko_lama', 'domain' => 'toko.com', 'server_id' => 5], $this->rows);
        $this->assertSame(7, $r['userid']);
    }

    public function testUsernameFallback(): void {
        $r = Resolver::resolve(['user' => 'lain', 'domain' => 'pindah.com', 'server_id' => 5], $this->rows);
        $this->assertSame(9, $r['userid']);
    }

    public function testUnmapped(): void {
        $this->assertNull(Resolver::resolve(['user' => 'hantu', 'domain' => 'hantu.com', 'server_id' => 5], $this->rows));
    }
}
