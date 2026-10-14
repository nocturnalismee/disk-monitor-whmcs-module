<?php
use PHPUnit\Framework\TestCase;
use DiskMonitor\Rules;

final class RulesTest extends TestCase {
    public function testEvaluate(): void {
        $this->assertSame('none', Rules::evaluate(84.9, false, 85, 95));
        $this->assertSame('warning', Rules::evaluate(85.0, false, 85, 95));
        $this->assertSame('warning', Rules::evaluate(94.9, false, 85, 95));
        $this->assertSame('critical', Rules::evaluate(95.0, false, 85, 95));
        $this->assertSame('critical', Rules::evaluate(50.0, true, 85, 95));
    }

    public function testShouldNotify(): void {
        $this->assertTrue(Rules::shouldNotify(null, 24, false, '2026-10-14 10:00:00'));
        $this->assertFalse(Rules::shouldNotify('2026-10-14 09:00:00', 24, false, '2026-10-14 10:00:00'));
        $this->assertTrue(Rules::shouldNotify('2026-10-13 09:00:00', 24, false, '2026-10-14 10:00:00'));
        $this->assertTrue(Rules::shouldNotify('2026-10-14 09:00:00', 24, true, '2026-10-14 10:00:00'));
    }
}
