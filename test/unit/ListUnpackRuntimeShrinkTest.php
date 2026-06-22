<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\ListUnpackJitHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPUnit\Framework\TestCase;

/** ListUnpack JIT routes value-box guards through ListUnpackJitHelper PHP (#10221, #10266). */
final class ListUnpackRuntimeShrinkTest extends TestCase
{
    public function testListUnpackRuntimeUsesListUnpackJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ListUnpackRuntime.php');
        $this->assertStringContainsString('ListUnpackJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('valueBoxIsArray', $source);
        $this->assertStringContainsString('valueBoxIsString', $source);
        $this->assertStringContainsString('valueBoxIsListDestructUnpackable', $source);
        $this->assertLessThan(160, substr_count($source, "\n") + 1);
    }

    public function testListUnpackHelperRoutesValueBoxThroughListUnpackRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ListUnpackHelper.php');
        $this->assertStringContainsString('ListUnpackRuntime', $source);
        $this->assertStringNotContainsString('isRuntimeTypeValue', $source);
        $this->assertLessThanOrEqual(235, substr_count($source, "\n") + 1);
        $this->assertLessThan(255, substr_count($source, "\n") + 1);
    }

    public function testListUnpackJitHelperValueBoxIsArray(): void
    {
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsArray(VmVariable::TYPE_ARRAY));
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsArray(JitVariable::TYPE_HASHTABLE));
        $this->assertFalse(ListUnpackJitHelper::valueBoxIsArray(VmVariable::TYPE_NULL));
        $this->assertFalse(ListUnpackJitHelper::valueBoxIsArray(VmVariable::TYPE_STRING));
    }

    public function testListUnpackJitHelperValueBoxIsString(): void
    {
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsString(VmVariable::TYPE_STRING));
        $this->assertFalse(ListUnpackJitHelper::valueBoxIsString(VmVariable::TYPE_ARRAY));
        $this->assertFalse(ListUnpackJitHelper::valueBoxIsString(VmVariable::TYPE_NULL));
    }

    public function testListUnpackJitHelperValueBoxIsListDestructUnpackable(): void
    {
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsListDestructUnpackable(
            VmVariable::TYPE_NULL,
            true
        ));
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsListDestructUnpackable(
            VmVariable::TYPE_ARRAY,
            false
        ));
        $this->assertFalse(ListUnpackJitHelper::valueBoxIsListDestructUnpackable(
            VmVariable::TYPE_STRING,
            false
        ));
    }
}
