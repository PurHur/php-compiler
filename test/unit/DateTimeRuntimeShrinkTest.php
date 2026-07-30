<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FormatDatetimeJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * date()/gmdate() NestedJIT via JitVmHelperLink::ensureCompiled (#25433 / peer #25365).
 */
final class DateTimeRuntimeShrinkTest extends TestCase
{
    public function testStringDateTimeUsesJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDateTime.php');
        $this->assertStringContainsString('FormatDatetimeJitHelper', $bridge);
        $this->assertStringContainsString('__compiler_format_datetime', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testFormatDatetimeJitHelperDelegatesToVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FormatDatetimeJitHelper.php');
        $this->assertStringContainsString('VmDate::date', $source);
        $this->assertStringContainsString('VmDate::gmdate', $source);

        $ts = 0;
        $this->assertSame(
            VmDate::date('Y-m-d', $ts),
            FormatDatetimeJitHelper::formatDatetimeArgv('Y-m-d', $ts, 0)
        );
        $this->assertSame(
            VmDate::gmdate('Y-m-d', $ts),
            FormatDatetimeJitHelper::formatDatetimeArgv('Y-m-d', $ts, 1)
        );
    }

    public function testSpineBundleIncludesFormatDatetimeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FormatDatetimeJitHelper.php', $spine);
        $this->assertStringContainsString('StringDateTime.php', $spine);
    }
}
