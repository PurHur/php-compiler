<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** get_class_methods() JIT routes through GetClassMethodsJitHelper PHP not inline LLVM (#16729). */
final class GetClassMethodsRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcClassMethodsCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_class_methods.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_class_methods.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/MethodRegistry.php');
    }

    public function testJitGetClassMethodsDelegatesToStringGetClassMethodsBridge(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/JitGetClassMethods.php');
        $this->assertStringContainsString('StringGetClassMethods::invoke', $source);
        $this->assertStringNotContainsString('invokeForRuntimeClassNameString', $source);
        $this->assertStringNotContainsString('invokeForEnumCaseValueBox', $source);
        $this->assertStringNotContainsString('invokeFromValueBox', $source);
        $this->assertStringNotContainsString('strcasecmp', $source);
        $this->assertLessThan(160, \substr_count($source, "\n") + 1);
    }

    public function testStringGetClassMethodsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringGetClassMethods.php');
        $this->assertStringContainsString('GetClassMethodsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }

    public function testGetClassMethodsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/GetClassMethodsJitHelper.php');
        $this->assertStringContainsString('VmReflection::requireClassForGetClassMethods', $source);
        $this->assertStringContainsString('VmReflection::classMethodsArray', $source);
        $this->assertStringContainsString('VmExecutingFrame::requireFromActiveContext', $source);
        $this->assertStringNotContainsString('Superglobals::getActiveContext', $source);
    }

    public function testJitGetClassMethodsAlwaysRoutesThroughPhpHelper(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/JitGetClassMethods.php');
        $this->assertStringContainsString('StringGetClassMethods::invoke', $source);
        $this->assertStringNotContainsString('allMethodNamesForClassId', $source);
        $this->assertStringNotContainsString('invokeForClassName', $source);
        $this->assertLessThan(160, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesGetClassMethodsJitHelper(): void
    {
        $spine = (string) file_get_contents($this->repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetClassMethodsJitHelper.php', $spine);
        $this->assertStringContainsString('StringGetClassMethods.php', $spine);
    }
}
