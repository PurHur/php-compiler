<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TypeErrorRaiseJitHelper;
use PHPUnit\Framework\TestCase;

/** TypeErrorRaise must route pending buffer through TypeErrorRaiseJitHelper PHP, not LLVM globals (#9778). */
final class TypeErrorRaiseRuntimeShrinkTest extends TestCase
{
    public function testTypeErrorRaiseUsesTypeErrorRaiseJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TypeErrorRaise.php');
        $this->assertStringContainsString('TypeErrorRaiseJitHelper', $source);
        $this->assertStringNotContainsString("addGlobal(\$i8, 'phpc_jit_type_error_pending_flag')", $source);
        $this->assertStringNotContainsString("addGlobal(\$msgTy, 'phpc_jit_type_error_pending_msg')", $source);
        $this->assertStringNotContainsString("addGlobal(\$i32, 'phpc_jit_type_error_pending_kind')", $source);
        $this->assertStringNotContainsString('implementRaiseFunction', $source);
    }

    public function testTypeErrorRaiseJitHelperPendingKinds(): void
    {
        TypeErrorRaiseJitHelper::clearPending();
        $this->assertFalse(TypeErrorRaiseJitHelper::hasPending());

        TypeErrorRaiseJitHelper::raiseTypeError('bad type');
        $this->assertTrue(TypeErrorRaiseJitHelper::hasPending());
        $this->assertSame(TypeErrorRaiseJitHelper::KIND_TYPE_ERROR, TypeErrorRaiseJitHelper::pendingKind());
        $this->assertSame('bad type', TypeErrorRaiseJitHelper::takePending());

        TypeErrorRaiseJitHelper::raiseArgumentCountError('too few');
        $this->assertSame(TypeErrorRaiseJitHelper::KIND_ARGUMENT_COUNT_ERROR, TypeErrorRaiseJitHelper::pendingKind());
        $this->assertSame('too few', TypeErrorRaiseJitHelper::takePending());

        TypeErrorRaiseJitHelper::raiseValueError('invalid');
        $this->assertSame(TypeErrorRaiseJitHelper::KIND_VALUE_ERROR, TypeErrorRaiseJitHelper::pendingKind());
        $this->assertSame('invalid', TypeErrorRaiseJitHelper::takePending());
    }
}
