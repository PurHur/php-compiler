<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TimezoneOffsetJitHelper;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPUnit\Framework\TestCase;

/** TimezoneOffsetRuntime routes through TimezoneOffsetJitHelper PHP not libc LLVM (#9452). */
final class TimezoneOffsetRuntimeShrinkTest extends TestCase
{
    public function testTimezoneOffsetRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TimezoneOffsetRuntime.php');
        $this->assertStringContainsString('TimezoneOffsetJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('setenv')", $source);
        $this->assertStringNotContainsString("lookupFunction('localtime_r')", $source);
        $this->assertStringNotContainsString("lookupFunction('timegm')", $source);
        $this->assertLessThan(180, \substr_count($source, "\n") + 1);
    }

    public function testTimezoneOffsetJitHelperDelegatesToVmDateTimeNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TimezoneOffsetJitHelper.php');
        $this->assertStringContainsString('VmDateTimeNative::timezoneOffsetSeconds', $source);
    }

    public function testTimezoneOffsetJitHelperSemanticsMatchVmDateTimeNative(): void
    {
        $ts = 1717243200;
        $this->assertSame(
            VmDateTimeNative::timezoneOffsetSeconds('Europe/Berlin', $ts),
            TimezoneOffsetJitHelper::offsetSeconds('Europe/Berlin', $ts)
        );
        $this->assertSame(7200, TimezoneOffsetJitHelper::offsetSeconds('Europe/Berlin', $ts));
    }
}
