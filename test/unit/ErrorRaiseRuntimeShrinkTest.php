<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ErrorRaiseJitHelper;
use PHPUnit\Framework\TestCase;

/** ErrorRaise must route pending buffer through ErrorRaiseJitHelper PHP, not LLVM globals (#9778). */
final class ErrorRaiseRuntimeShrinkTest extends TestCase
{
    public function testErrorRaiseUsesErrorRaiseJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ErrorRaise.php');
        $this->assertStringContainsString('ErrorRaiseJitHelper', $source);
        $this->assertStringNotContainsString("addGlobal(\$i8, 'phpc_jit_error_pending_flag')", $source);
        $this->assertStringNotContainsString("addGlobal(\$msgTy, 'phpc_jit_error_pending_msg')", $source);
        $this->assertStringNotContainsString('implementRaiseFunction', $source);
    }

    public function testErrorRaiseJitHelperPendingLifecycle(): void
    {
        ErrorRaiseJitHelper::clearPending();
        $this->assertFalse(ErrorRaiseJitHelper::hasPending());

        ErrorRaiseJitHelper::raise('readonly write');
        $this->assertTrue(ErrorRaiseJitHelper::hasPending());
        $this->assertSame('readonly write', ErrorRaiseJitHelper::takePending());
        $this->assertFalse(ErrorRaiseJitHelper::hasPending());
        $this->assertSame('', ErrorRaiseJitHelper::takePending());
    }
}
