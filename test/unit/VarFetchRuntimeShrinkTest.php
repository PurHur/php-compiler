<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VmVarFetch;
use PHPUnit\Framework\TestCase;

/** VarFetch JIT routes binding resolution through VmVarFetch PHP (#10289). */
final class VarFetchRuntimeShrinkTest extends TestCase
{
    public function testVarFetchRuntimeUsesVmVarFetchJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/VarFetchRuntime.php');
        $this->assertStringContainsString('VmVarFetchJitHelper', $source);
        $this->assertStringContainsString('isSuperglobalName', $source);
        $this->assertStringNotContainsString('operandBindingRank', $source);
        $this->assertFileExists(__DIR__.'/../../lib/VM/VmVarFetchJitHelper.php');
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
}
