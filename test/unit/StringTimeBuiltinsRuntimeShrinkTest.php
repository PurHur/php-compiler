<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GmgetdateJitHelper;
use PHPCompiler\ext\standard\IdateJitHelper;
use PHPCompiler\ext\standard\LocaltimeJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** localtime/idate/gmgetdate route through VmDate PHP helpers not libc LLVM (#9181). */
final class StringTimeBuiltinsRuntimeShrinkTest extends TestCase
{
    public function testStringLocaltimeRoutesThroughLocaltimeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLocaltime.php');
        $this->assertStringContainsString('LocaltimeJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('localtime')", $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertLessThan(170, \substr_count($source, "\n") + 1);
    }

    public function testStringIdateRoutesThroughIdateJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringIdate.php');
        $this->assertStringContainsString('IdateJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('localtime')", $source);
        $this->assertStringNotContainsString('TM_SEC', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testStringGmgetdateRoutesThroughGmgetdateJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGmgetdate.php');
        $this->assertStringContainsString('GmgetdateJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('gmtime')", $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertLessThan(170, \substr_count($source, "\n") + 1);
    }

    public function testLocaltimeJitHelperSemanticsMatchVmDate(): void
    {
        $expected = VmDate::localtimeBreakdown(0, true);
        $actual = LocaltimeJitHelper::localtime(0, true);
        foreach (['tm_sec', 'tm_min', 'tm_hour', 'tm_mday', 'tm_mon', 'tm_year', 'tm_wday', 'tm_yday', 'tm_isdst'] as $key) {
            $this->assertSame(
                $expected->find($key)->resolveIndirect()->toInt(),
                $actual->find($key)->resolveIndirect()->toInt(),
                'mismatch for '.$key
            );
        }
    }

    public function testIdateJitHelperSemanticsMatchVmDate(): void
    {
        $this->assertSame(VmDate::idateValue('Y', 0), IdateJitHelper::idate('Y', 0));
        $this->assertFalse(VmDate::idateValue('X', 0));
        $this->assertSame(-2, @IdateJitHelper::idate('X', 0));
    }

    public function testGmgetdateJitHelperSemanticsMatchVmDate(): void
    {
        $expected = VmDate::gmgetdate(946684800);
        $actual = GmgetdateJitHelper::gmgetdate(946684800);
        $this->assertSame($expected->find('year')->resolveIndirect()->toInt(), $actual->find('year')->resolveIndirect()->toInt());
        $this->assertSame($expected->find('month')->resolveIndirect()->toString(), $actual->find('month')->resolveIndirect()->toString());
    }
}
