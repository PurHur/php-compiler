<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\InstanceOfClassName;
use PHPCompiler\VM\InstanceOfJitHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPUnit\Framework\TestCase;

/** Instanceof JIT routes dynamic RHS guards through InstanceOfJitHelper PHP (#10078). */
final class InstanceOfRuntimeShrinkTest extends TestCase
{
    public function testInstanceOfHelperRoutesValueBoxThroughInstanceOfJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/InstanceOfHelper.php');
        $this->assertStringContainsString('InstanceOfJitHelper', $source);
        $this->assertStringContainsString('valueBoxRhsKind', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('jitRhsTypeIsInvalidClass', $source);
        // #32766: length-aware strncasecmp against allDeclaredClassLowerNames.
        $this->assertStringContainsString('allDeclaredClassLowerNames', $source);
        $this->assertStringContainsString('ABI_STRNCASECMP', $source);
        $this->assertStringContainsString('restoreInsertBlock', $source);
        $this->assertStringContainsString('valueBoxRhsKind', $source);
    }

    public function testInstanceOfHelperSharesErrorMessageWithVm(): void
    {
        $this->assertSame(InstanceOfClassName::ERROR_MESSAGE, \PHPCompiler\JIT\InstanceOfHelper::ERROR_MESSAGE);
    }

    public function testInstanceOfJitHelperJitRhsTypeIsInvalidClass(): void
    {
        $this->assertTrue(InstanceOfJitHelper::jitRhsTypeIsInvalidClass(JitVariable::TYPE_NULL));
        $this->assertTrue(InstanceOfJitHelper::jitRhsTypeIsInvalidClass(JitVariable::TYPE_NATIVE_LONG));
        $this->assertTrue(InstanceOfJitHelper::jitRhsTypeIsInvalidClass(JitVariable::TYPE_HASHTABLE));
        $this->assertFalse(InstanceOfJitHelper::jitRhsTypeIsInvalidClass(JitVariable::TYPE_STRING));
        $this->assertFalse(InstanceOfJitHelper::jitRhsTypeIsInvalidClass(JitVariable::TYPE_OBJECT));
        $this->assertFalse(InstanceOfJitHelper::jitRhsTypeIsInvalidClass(JitVariable::TYPE_VALUE));
    }

    public function testInstanceOfJitHelperValueBoxRhsKind(): void
    {
        $this->assertSame(
            InstanceOfJitHelper::RHS_KIND_STRING,
            InstanceOfJitHelper::valueBoxRhsKind(VmVariable::TYPE_STRING)
        );
        $this->assertSame(
            InstanceOfJitHelper::RHS_KIND_OBJECT,
            InstanceOfJitHelper::valueBoxRhsKind(VmVariable::TYPE_OBJECT)
        );
        $this->assertSame(
            InstanceOfJitHelper::RHS_KIND_INVALID,
            InstanceOfJitHelper::valueBoxRhsKind(VmVariable::TYPE_NULL)
        );
        $this->assertSame(
            InstanceOfJitHelper::RHS_KIND_INVALID,
            InstanceOfJitHelper::valueBoxRhsKind(VmVariable::TYPE_ARRAY)
        );
        // JIT boxes store TYPE_STRING|IS_REFCOUNTED (0x80) — must still classify (#32766).
        $this->assertSame(
            InstanceOfJitHelper::RHS_KIND_STRING,
            InstanceOfJitHelper::valueBoxRhsKind(JitVariable::TYPE_STRING)
        );
        $this->assertSame(
            InstanceOfJitHelper::RHS_KIND_OBJECT,
            InstanceOfJitHelper::valueBoxRhsKind(JitVariable::TYPE_OBJECT)
        );
    }
}
