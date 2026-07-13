<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** get_parent_class() JIT routes through GetParentClassJitHelper PHP not inline LLVM (php-in-PHP, #1492). */
final class GetParentClassRuntimeShrinkTest extends TestCase
{
    public function testJitGetParentClassDelegatesToStringGetParentClassBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetParentClass.php');
        $this->assertStringContainsString('StringGetParentClass::invoke', $source);
        $this->assertStringContainsString('routeThroughPhpHelper', $source);
        $this->assertStringNotContainsString('invokeForObject', $source);
        $this->assertStringNotContainsString('invokeForBoxedValue', $source);
        $this->assertLessThan(160, substr_count($source, "\n") + 1);
    }

    public function testStringGetParentClassUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetParentClass.php');
        $this->assertStringContainsString('GetParentClassJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }

    public function testGetParentClassJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetParentClassJitHelper.php');
        $this->assertStringContainsString('VmReflection::parentClassName', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }

    public function testSpineBundleIncludesGetParentClassJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetParentClassJitHelper.php', $spine);
        $this->assertStringContainsString('StringGetParentClass.php', $spine);
    }
}
