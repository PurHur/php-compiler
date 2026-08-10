<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TimezoneOffsetJitHelper;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPUnit\Framework\TestCase;

/**
 * TimezoneOffsetRuntime routes through TimezoneOffsetJitHelper PHP via
 * JitVmHelperLink::ensureCompiled (#9452 / #25042 / peer #24962).
 */
final class TimezoneOffsetRuntimeShrinkTest extends TestCase
{
    public function testTimezoneOffsetRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TimezoneOffsetRuntime.php');
        $this->assertStringContainsString('TimezoneOffsetJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString("lookupFunction('setenv')", $source);
        $this->assertStringNotContainsString("lookupFunction('localtime_r')", $source);
        $this->assertStringNotContainsString("lookupFunction('timegm')", $source);
        // Thin AOT getOffset needs insert-block restore (#29732 / peer LocationRuntime).
        $this->assertStringContainsString('getInsertBlock', $source);
        $this->assertStringContainsString('positionAtEnd($savedBlock)', $source);
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
