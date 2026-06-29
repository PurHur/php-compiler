<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GettimeofdayJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** StringGettimeofday routes through GettimeofdayJitHelper PHP not libc gettimeofday LLVM (#13764). */
final class GettimeofdayRuntimeShrinkTest extends TestCase
{
    public function testStringGettimeofdayRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGettimeofday.php');
        $this->assertStringContainsString('GettimeofdayJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('gettimeofday')", $source);
        $this->assertStringNotContainsString('ensureLibcGettimeofday', $source);
        $this->assertStringNotContainsString('TIMEVAL_SIZE', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }

    public function testGettimeofdayJitHelperDelegatesToVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GettimeofdayJitHelper.php');
        $this->assertStringContainsString('VmDate::gettimeofdayFloat', $source);
        $this->assertStringContainsString('VmDate::gettimeofdayArray', $source);
        $this->assertStringContainsString('VmDate::wallClock', $source);
    }

    public function testGettimeofdayJitHelperSemanticsMatchVmDate(): void
    {
        $float = GettimeofdayJitHelper::gettimeofdayFloat();
        $this->assertIsFloat($float);
        $this->assertGreaterThan(1_000_000_000.0, $float);

        $ht = GettimeofdayJitHelper::gettimeofdayArray();
        foreach (['sec', 'usec', 'minuteswest', 'dsttime'] as $key) {
            $this->assertNotNull($ht->find($key), 'missing key: '.$key);
        }

        $vmHt = VmDate::gettimeofdayArray();
        $this->assertSame(
            $vmHt->find('sec')->resolveIndirect()->toInt(),
            $ht->find('sec')->resolveIndirect()->toInt()
        );
    }

    public function testWallClockHelpersReturnReasonableValues(): void
    {
        $sec = GettimeofdayJitHelper::wallClockSec();
        $usec = GettimeofdayJitHelper::wallClockUsecMasked(0x100000);
        $this->assertGreaterThan(1_000_000_000, $sec);
        $this->assertGreaterThanOrEqual(0, $usec);
        $this->assertLessThan(0x100000, $usec);
    }
}
