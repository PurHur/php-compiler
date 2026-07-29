<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHrtime;
use PHPCompiler\ext\standard\VmHrtimeNative;
use PHPUnit\Framework\TestCase;

/** @covers issue #5174 #7315 */
final class VmHrtimeTest extends TestCase
{
    public function testMonotonicNanosecondsAndPair(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/uptime')) {
            $this->markTestSkipped('/proc/uptime unavailable');
        }
        $a = VmHrtime::hrtime(true);
        \usleep(100);
        $b = VmHrtime::hrtime(true);
        if (\PHPCompiler\CompilerVersion::supportsHrtimeAsNumberFloat()) {
            $this->assertIsFloat($a);
        } else {
            $this->assertIsInt($a);
        }
        $this->assertGreaterThan(0, $a);
        $this->assertGreaterThan(0, $b);
        $this->assertGreaterThanOrEqual($a, $b, 'hrtime(true) must be monotonic (#23420)');

        $pair = VmHrtime::hrtime(false);
        $this->assertIsArray($pair);
        $this->assertCount(2, $pair);
        $this->assertGreaterThanOrEqual(0, $pair[0]);
        $this->assertGreaterThanOrEqual(0, $pair[1]);
    }

    public function testNativeReadDoesNotRequireFfi(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/uptime')) {
            $this->markTestSkipped('/proc/uptime unavailable');
        }
        [$sec, $nsec] = VmHrtimeNative::readMonotonic();
        $this->assertGreaterThan(0, $sec + $nsec);
    }

    /** Issue #10859 — realtime microtime path exposes sub-microsecond nanoseconds. */
    public function testNanosecondSubMicrosecondPrecision(): void
    {
        $anyNonZeroMod = false;
        for ($i = 0; $i < 64; ++$i) {
            $pair = VmHrtimeNative::readClock(VmHrtimeNative::CLOCK_REALTIME);
            $this->assertIsArray($pair);
            [, $nsec] = $pair;
            if (0 !== $nsec % 1000) {
                $anyNonZeroMod = true;

                break;
            }
        }
        $this->assertTrue($anyNonZeroMod, 'readClock(REALTIME) nsec % 1000 should be non-zero with microtime');

        // Poll total ns — consecutive nsec-within-second samples often match (#24870).
        $a = VmHrtimeNative::readClock(VmHrtimeNative::CLOCK_REALTIME);
        $this->assertIsArray($a);
        $aTotal = $a[0] * VmHrtimeNative::NS_PER_SEC + $a[1];
        $bTotal = $aTotal;
        for ($i = 0; $i < 10000; ++$i) {
            $b = VmHrtimeNative::readClock(VmHrtimeNative::CLOCK_REALTIME);
            $this->assertIsArray($b);
            $bTotal = $b[0] * VmHrtimeNative::NS_PER_SEC + $b[1];
            if ($bTotal !== $aTotal) {
                break;
            }
        }
        $this->assertNotSame($aTotal, $bTotal, 'realtime clock total ns should advance');
    }

    /** Issue #12279 / #24870 — monotonic path exposes sub-ms nanoseconds via microtime refinement. */
    public function testMonotonicNanosecondSubMillisecondPrecision(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/uptime')) {
            $this->markTestSkipped('/proc/uptime unavailable');
        }
        $anyNonZeroMod = false;
        for ($i = 0; $i < 64; ++$i) {
            [, $nsec] = VmHrtime::hrtime(false);
            if (0 !== $nsec % 1000) {
                $anyNonZeroMod = true;

                break;
            }
        }
        $this->assertTrue($anyNonZeroMod, 'hrtime()[1] % 1000 should be non-zero with microtime refinement');

        $a = VmHrtime::hrtime(true);
        $b = $a;
        for ($i = 0; $i < 10000; ++$i) {
            $b = VmHrtime::hrtime(true);
            if ($b !== $a) {
                break;
            }
        }
        $this->assertNotSame($a, $b, 'hrtime(true) should advance within a bounded poll (#24870)');
    }
}
