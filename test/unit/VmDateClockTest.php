<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** @covers issue #5045 */
final class VmDateClockTest extends TestCase
{
    private const Y2K = 946684800;

    public function testGmdateMatchesZendSubset(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('FFI extension required for VmDate clock');
        }
        $this->assertSame('2000-01-01 00:00:00', VmDate::gmdate('Y-m-d H:i:s', self::Y2K));
        $this->assertSame('00', VmDate::gmdate('H', self::Y2K));
        $this->assertSame(4, \strlen(VmDate::gmdate('Y', self::Y2K)));
    }

    public function testTimeAndMicrotimeUseLibcNotHostZend(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('FFI extension required for VmDate clock');
        }
        $t = VmDate::time();
        $this->assertGreaterThan(self::Y2K, $t);
        $this->assertIsFloat(VmDate::microtime(true));
        $this->assertMatchesRegularExpression('/^\d+\.\d+ \d+$/', VmDate::microtime(false));
    }

    public function testGetdateBreakdownAtY2kUtc(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('FFI extension required for VmDate clock');
        }
        $ref = \getdate(self::Y2K);
        $ht = VmDate::getdate(self::Y2K);
        foreach (['seconds', 'minutes', 'hours', 'mday', 'wday', 'mon', 'year', 'yday'] as $key) {
            $slot = $ht->find($key);
            $this->assertNotNull($slot, $key);
            $this->assertSame((int) $ref[$key], $slot->toInt(), $key);
        }
        $this->assertSame('Saturday', $ht->find('weekday')->toString());
        $this->assertSame('January', $ht->find('month')->toString());
        $slot0 = $ht->findIndex(0);
        $this->assertNotNull($slot0);
        $this->assertSame(self::Y2K, $slot0->toInt());
    }
}
