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
        $this->assertStringContainsString('TypeErrorRaise::emitBranchOrAbortOnFailure', $source);
        $this->assertStringNotContainsString('isRuntimeTypeValue', $source);
        // LLVMBuildUnreachable after exit(255) for AOT object list-Error (#25096 / #23641).
        $this->assertLessThanOrEqual(360, substr_count($source, "\n") + 1);
    }

    public function testListUnpackJitHelperValueBoxIsArray(): void
    {
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsArray(VmVariable::TYPE_ARRAY));
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsArray(JitVariable::TYPE_HASHTABLE));
        // i8 ABI sign-extends TYPE_HASHTABLE (135) to -121 (#23971 e08_spread).
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsArray(-121));
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsArray(0x87));
        $this->assertFalse(ListUnpackJitHelper::valueBoxIsArray(VmVariable::TYPE_NULL));
        $this->assertFalse(ListUnpackJitHelper::valueBoxIsArray(VmVariable::TYPE_STRING));
    }

    public function testListUnpackJitHelperUsesNestedJitSafeLiterals(): void
    {
        // NestedJIT only folds int literals in === / if — not JitVariable:: or self:: consts (#28641).
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/ListUnpackJitHelper.php');
        $this->assertStringNotContainsString('JitVariable::', $source);
        $this->assertStringContainsString('135 === $typeByte', $source);
        $this->assertStringContainsString('132 === $typeByte', $source);
    }

    public function testListUnpackJitHelperValueBoxIsString(): void
    {
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsString(VmVariable::TYPE_STRING));
        // i8 sign-extend of JIT TYPE_STRING (132) → -124.
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsString((JitVariable::TYPE_STRING << 24) >> 24));
        $this->assertTrue(ListUnpackJitHelper::valueBoxIsString(0x84));
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