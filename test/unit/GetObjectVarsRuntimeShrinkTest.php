<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** get_object_vars() JIT routes through GetObjectVarsJitHelper PHP not inline LLVM (#16629). */
final class GetObjectVarsRuntimeShrinkTest extends TestCase
{
    public function testJitGetObjectVarsDelegatesToStringGetObjectVarsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetObjectVars.php');
        $this->assertStringContainsString('StringGetObjectVars::invoke', $source);
        $this->assertStringNotContainsString('invokeFromResolvedObject', $source);
        $this->assertStringNotContainsString('invokeWithEnumRuntimeDispatch', $source);
        $this->assertStringNotContainsString('invokeForPlainObject', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testStringGetObjectVarsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetObjectVars.php');
        $this->assertStringContainsString('GetObjectVarsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }

    public function testGetObjectVarsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetObjectVarsJitHelper.php');
        $this->assertStringContainsString('VmReflection::getObjectVars', $source);
        $this->assertStringContainsString('VmReflection::getMangledObjectVars', $source);
        $this->assertStringContainsString('VmExecutingFrame::requireFromActiveContext', $source);
    }

    public function testSpineBundleIncludesGetObjectVarsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetObjectVarsJitHelper.php', $spine);
        $this->assertStringContainsString('StringGetObjectVars.php', $spine);
    }
}
