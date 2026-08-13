<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VmVarFetch;
use PHPUnit\Framework\TestCase;

/** VarFetch JIT routes binding resolution through VmVarFetch PHP (#10289, #25328). */
final class VarFetchRuntimeShrinkTest extends TestCase
{
    public function testVarFetchRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/VarFetchRuntime.php');
        $this->assertStringContainsString('VmVarFetchJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
    }

    public function testVarFetchRuntimeUsesVmVarFetchJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/VarFetchRuntime.php');
        $this->assertStringContainsString('isSuperglobalName', $source);
        $this->assertStringNotContainsString('operandBindingRank', $source);
        $this->assertFileExists(__DIR__.'/../../lib/VM/VmVarFetchJitHelper.php');
        $this->assertStringNotContainsString('SuperglobalNames::ALL', $source);
        $this->assertStringNotContainsString("lookupFunction('strcmp')", $source);
        $this->assertLessThan(210, \substr_count($source, "\n") + 1);
    }

    /** Bridge ABI is C-string (int8*), not PHP `string*` (#30779). */
    public function testVarFetchSuperglobalBridgeUsesInt8PtrNotStringStar(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/VarFetchRuntime.php');
        $this->assertStringContainsString("getTypeFromString('int8*')", $source);
        $this->assertStringNotContainsString("getTypeFromString('string*')", $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
    }

    public function testVarFetchRuntimeLazyLinkedFromHelper(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringNotContainsString('VarFetchRuntime::ensureLinked', $type);
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/VarFetchHelper.php');
        $this->assertStringContainsString('VarFetchRuntime::ensureLinked', $helper);
    }

    public function testVarFetchHelperRoutesThroughVmVarFetch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/VarFetchHelper.php');
        $this->assertStringContainsString('VmVarFetch', $source);
        $this->assertStringContainsString('VarFetchRuntime', $source);
        $this->assertStringNotContainsString('operandBindingRank', $source);
        $this->assertLessThanOrEqual(40, substr_count($source, "\n") + 1);
    }

    public function testVmVarFetchIsSuperglobalName(): void
    {
        $this->assertTrue(VmVarFetch::isSuperglobalName('_GET'));
        $this->assertTrue(VmVarFetch::isSuperglobalName('_POST'));
        $this->assertFalse(VmVarFetch::isSuperglobalName('x'));
        $this->assertFalse(VmVarFetch::isSuperglobalName('this'));
    }

    public function testSpineBundleIncludesVmVarFetchJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VmVarFetchJitHelper.php', $spine);
        $this->assertStringContainsString('VarFetchRuntime.php', $spine);
    }
}
