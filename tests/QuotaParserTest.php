<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\QuotaParser;

final class QuotaParserTest extends TestCase {
    public function testToMb(): void {
        $this->assertSame(512.0, QuotaParser::toMb('512M'));
        $this->assertSame(1024.0, QuotaParser::toMb('1G'));
        $this->assertSame(1.5, QuotaParser::toMb('1536K'));
        $this->assertNull(QuotaParser::toMb('unlimited'));
        $this->assertNull(QuotaParser::toMb('0'));
        $this->assertNull(QuotaParser::toMb(''));
    }

    public function testPct(): void {
        $this->assertSame(92.1, QuotaParser::pct(920.5, 1000.0));
        $this->assertNull(QuotaParser::pct(100.0, null));
    }
}
