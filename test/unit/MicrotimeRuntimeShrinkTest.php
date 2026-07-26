<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MicrotimeJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * microtime() NestedJIT via JitVmHelperLink::ensureCompiled (#23556 / peer #22519).
 */
final class MicrotimeRuntimeShrinkTest extends TestCase
{
    public function testMicrotimeUsesJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMicrotime.php');
        $this->assertStringContainsString('MicrotimeJitHelper', $bridge);
        $this->assertStringContainsString('__compiler_microtime_float', $bridge);
        $this->assertStringContainsString('__compiler_microtime_string', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testMicrotimeJitHelperDelegatesToVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/MicrotimeJitHelper.php');
        $this->assertStringContainsString('VmDate::microtime', $source);

        $float = MicrotimeJitHelper::microtimeFloat();
        $this->assertIsFloat($float);
        $this->assertGreaterThan(946684800.0, $float);
        $this->assertEqualsWithDelta(VmDate::microtime(true), $float, 1.0);

        $string = MicrotimeJitHelper::microtimeString();
        $parts = explode(' ', $string);
        $this->assertCount(2, $parts);
        $this->assertTrue(is_numeric($parts[0]));
        $this->assertTrue(is_numeric($parts[1]));
    }

    public function testSpineBundleIncludesMicrotimeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('MicrotimeJitHelper.php', $spine);
        $this->assertStringContainsString('StringMicrotime.php', $spine);
    }
}
