<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** class_implements() JIT routes through ClassImplementsJitHelper PHP not inline LLVM (#16960). */
final class ClassImplementsRuntimeShrinkTest extends TestCase
{
    public function testJitClassImplementsDelegatesToStringClassImplementsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitClassImplements.php');
        $this->assertStringContainsString('StringClassImplements::invoke', $source);
        $this->assertStringContainsString('routeThroughPhpHelper', $source);
        $this->assertStringContainsString('ClassImplementsJitHelper', (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringClassImplements.php'));
    }

    public function testStringClassImplementsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringClassImplements.php');
        $this->assertStringContainsString('ClassImplementsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }

    public function testClassImplementsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ClassImplementsJitHelper.php');
        $this->assertStringContainsString('VmReflection::resolveClassForClassImplements', $source);
        $this->assertStringContainsString('VmReflection::classImplementsArray', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }

    public function testSpineBundleIncludesClassImplementsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ClassImplementsJitHelper.php', $spine);
        $this->assertStringContainsString('StringClassImplements.php', $spine);
    }
}
