<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** get_object_vars() embed uses GetObjectVarsJitHelper; standalone AOT uses native LLVM (#16629, #26797). */
final class GetObjectVarsRuntimeShrinkTest extends TestCase
{
    public function testJitGetObjectVarsRoutesEmbedThroughHelperAndStandaloneThroughNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetObjectVars.php');
        $this->assertStringContainsString('StringGetObjectVars::invoke', $source);
        $this->assertStringContainsString('JitGetObjectVarsNative::invoke', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('invokeFromResolvedObject', $source);
        $this->assertStringNotContainsString('invokeForPlainObject', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testNativeStandaloneOwnsClassIdDispatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetObjectVarsNative.php');
        $this->assertStringContainsString('invokeFromResolvedObject', $source);
        $this->assertStringContainsString('invokeForPlainObject', $source);
        $this->assertStringContainsString('manglePropertyKey', $source);
        $this->assertStringContainsString('#26797', $source);
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

    public function testSpineBundleIncludesGetObjectVarsHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetObjectVarsJitHelper.php', $spine);
        $this->assertStringContainsString('JitGetObjectVarsNative.php', $spine);
        $this->assertStringContainsString('StringGetObjectVars.php', $spine);
    }
}
