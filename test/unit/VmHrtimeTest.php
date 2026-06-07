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
        $b = VmHrtime::hrtime(true);
        $this->assertGreaterThan(0, $a);
        $this->assertGreaterThanOrEqual($a, $b);

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
}
