<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IdateJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * StringIdate NestedJIT via JitVmHelperLink::ensureCompiled (#24382 / peer #24094).
 */
final class IdateRuntimeShrinkTest extends TestCase
{
    public function testStringIdateUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringIdate.php');
        $this->assertStringContainsString('IdateJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(100, \substr_count($source, "\n") + 1);
    }

    public function testIdateJitHelperDelegatesToVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IdateJitHelper.php');
        $this->assertStringContainsString('VmDate::idateValue', $source);
    }

    public function testIdateJitHelperSemanticsMatchVmDate(): void
    {
        $ts = strtotime('2020-06-21 12:00:00 UTC');
        $this->assertSame(VmDate::idateValue('Y', $ts), IdateJitHelper::idate('Y', $ts));
        $this->assertSame(VmDate::idateValue('m', $ts), IdateJitHelper::idate('m', $ts));
        $this->assertSame(VmDate::idateValue('d', $ts), IdateJitHelper::idate('d', $ts));
    }

    public function testSpineBundleIncludesIdateJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IdateJitHelper.php', $spine);
        $this->assertStringContainsString('StringIdate.php', $spine);
    }
}
