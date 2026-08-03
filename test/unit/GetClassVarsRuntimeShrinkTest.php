<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** get_class_vars() JIT routes through GetClassVarsJitHelper PHP not inline LLVM (#16713, #27229). */
final class GetClassVarsRuntimeShrinkTest extends TestCase
{
    public function testJitGetClassVarsDelegatesToStringGetClassVarsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetClassVars.php');
        $this->assertStringContainsString('StringGetClassVars::invoke', $source);
        $this->assertStringContainsString('GetClassVarsRuntime::emitForClassName', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('emitFromVmClass', $source);
        $this->assertStringNotContainsString('emitFromObjectRegistry', $source);
        $this->assertStringNotContainsString('storeCompileTimeDefault', $source);
        $this->assertLessThan(90, \substr_count($source, "\n") + 1);
    }

    public function testStringGetClassVarsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetClassVars.php');
        $this->assertStringContainsString('GetClassVarsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }

    public function testGetClassVarsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetClassVarsJitHelper.php');
        $this->assertStringContainsString('VmReflection::fetchClassEntryForGetClassVars', $source);
        $this->assertStringContainsString('VmReflection::getClassVarsArray', $source);
        $this->assertStringContainsString('VmExecutingFrame::requireFromActiveContext', $source);
    }

    public function testGetClassVarsRuntimeMaterializesFromObjectRegistry(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetClassVarsRuntime.php');
        $this->assertStringContainsString('propertyDefaultEntries', $source);
        $this->assertStringContainsString('publicStaticPropertyDefaultEntries', $source);
        $this->assertStringContainsString('__value__writeHashtable', $source);
        $this->assertStringContainsString('#27229', $source);
    }

    public function testSpineBundleIncludesGetClassVarsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetClassVarsJitHelper.php', $spine);
        $this->assertStringContainsString('StringGetClassVars.php', $spine);
        $this->assertStringContainsString('GetClassVarsRuntime.php', $spine);
    }
}
