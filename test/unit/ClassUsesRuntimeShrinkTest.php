<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** class_uses() JIT routes through ClassUsesJitHelper PHP not inline LLVM (#16501). */
final class ClassUsesRuntimeShrinkTest extends TestCase
{
    public function testJitClassUsesDelegatesToStringClassUsesBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitClassUses.php');
        $this->assertStringContainsString('StringClassUses::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        $this->assertStringNotContainsString('invokeForRuntimeClassNameString', $source);
        $this->assertStringNotContainsString('invokeForObject', $source);
        $this->assertStringNotContainsString('invokeForBoxedValue', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testStringClassUsesUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringClassUses.php');
        $this->assertStringContainsString('ClassUsesJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
    }

    public function testClassUsesJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ClassUsesJitHelper.php');
        $this->assertStringContainsString('VmReflection::resolveClassForClassUses', $source);
        $this->assertStringContainsString('VmReflection::classUsesArray', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }

    public function testSpineBundleIncludesClassUsesJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ClassUsesJitHelper.php', $spine);
        $this->assertStringContainsString('StringClassUses.php', $spine);
    }
}
