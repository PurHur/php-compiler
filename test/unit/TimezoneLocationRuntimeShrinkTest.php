<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TimezoneLocationJitHelper;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPUnit\Framework\TestCase;

/** TimezoneLocationRuntime routes through TimezoneLocationJitHelper PHP not zone.tab LLVM (#9451). */
final class TimezoneLocationRuntimeShrinkTest extends TestCase
{
    public function testTimezoneLocationRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TimezoneLocationRuntime.php');
        $this->assertStringContainsString('TimezoneLocationJitHelper', $source);
        $this->assertStringNotContainsString('exportZoneTabEntries', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        $this->assertStringNotContainsString('emitLocationHashtable', $source);
        $this->assertLessThan(140, \substr_count($source, "\n") + 1);
    }

    public function testTimezoneLocationJitHelperDelegatesToVmDateTimeNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TimezoneLocationJitHelper.php');
        $this->assertStringContainsString('VmDateTimeNative::timezoneLocation', $source);
    }

    public function testTimezoneLocationJitHelperSemanticsMatchVmDateTimeNative(): void
    {
        $ht = TimezoneLocationJitHelper::locationHashtable('Europe/Berlin');
        $this->assertNotNull($ht);
        $this->assertSame('DE', $ht->find('country_code')->resolveIndirect()->toString());
        $native = VmDateTimeNative::timezoneLocation('Europe/Berlin');
        $this->assertIsArray($native);
        $this->assertSame($native['country_code'], $ht->find('country_code')->resolveIndirect()->toString());
        $this->assertNull(TimezoneLocationJitHelper::locationHashtable('Not/A/Real/Zone'));
    }
}
