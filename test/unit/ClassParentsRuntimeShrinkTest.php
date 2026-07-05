<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** class_parents() JIT routes through ClassParentsJitHelper PHP not inline LLVM (#16586). */
final class ClassParentsRuntimeShrinkTest extends TestCase
{
    public function testJitClassParentsDelegatesToStringClassParentsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitClassParents.php');
        $this->assertStringContainsString('StringClassParents::invoke', $source);
        $this->assertStringNotContainsString('invokeForObject', $source);
        $this->assertStringNotContainsString('invokeForBoxedValue', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testStringClassParentsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringClassParents.php');
        $this->assertStringContainsString('ClassParentsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }

    public function testClassParentsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ClassParentsJitHelper.php');
        $this->assertStringContainsString('VmReflection::resolveClassForClassImplements', $source);
        $this->assertStringContainsString('VmReflection::classParentsArray', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }

    public function testSpineBundleIncludesClassParentsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ClassParentsJitHelper.php', $spine);
        $this->assertStringContainsString('StringClassParents.php', $spine);
    }
}
