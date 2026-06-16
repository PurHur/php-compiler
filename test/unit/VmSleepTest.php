<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmSleep;
use PHPUnit\Framework\TestCase;

/** Issue #4860: VM sleep/usleep must not delegate to host \\sleep()/\\usleep(). */
final class VmSleepTest extends TestCase
{
    public function testVmSleepDoesNotReferenceHostSleep(): void
    {
        $source = file_get_contents(__DIR__.'/../../ext/standard/VmSleep.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('\\sleep(', $source);
        $this->assertStringNotContainsString('\\usleep(', $source);
        $this->assertStringNotContainsString('\\time_nanosleep(', $source);
        $this->assertStringNotContainsString('\\time_sleep_until(', $source);
        $this->assertStringContainsString('VmSleepNative', $source);
    }

    public function testSleepZeroReturnsZero(): void
    {
        $this->assertSame(0, VmSleep::sleep(0));
    }

    public function testUsleepAdvancesClock(): void
    {
        $t0 = hrtime(true);
        VmSleep::usleep(1000);
        $this->assertGreaterThan($t0, hrtime(true));
    }

    public function testNegativeSleepThrowsValueError(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('sleep(): Argument #1 ($seconds) must be greater than or equal to 0');
        VmSleep::sleep(-1);
    }

    public function testNegativeUsleepThrowsValueError(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('usleep(): Argument #1 ($microseconds) must be greater than or equal to 0');
        VmSleep::usleep(-1);
    }
}
