<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ReturnPendingJitHelper;
use PHPUnit\Framework\TestCase;

/** Return-through-finally JIT bridge uses ReturnPendingJitHelper PHP SSOT (#9663). */
final class ReturnPendingRuntimeShrinkTest extends TestCase
{
    public function testJitReturnPendingRoutesThroughReturnPendingRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JitReturnPending.php');
        $this->assertStringContainsString('ReturnPendingRuntime::implement', $source);
        $this->assertStringNotContainsString('phpc_jit_return_flag', $source);
        $this->assertStringNotContainsString('implementPendingHelpers', $source);
        $this->assertStringNotContainsString('registerPendingGlobals', $source);
    }

    public function testReturnPendingRuntimeIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ReturnPendingRuntime.php');
        $this->assertStringContainsString('JitHelperAbiBridge::implement', $source);
        $this->assertStringContainsString('ReturnPendingJitHelper', $source);
        $lineCount = substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(70, $lineCount, 'ReturnPendingRuntime should be a thin trampoline (#9663)');
    }

    public function testReturnPendingJitHelperLifecycle(): void
    {
        ReturnPendingJitHelper::resetForTest();
        $this->assertFalse(ReturnPendingJitHelper::hasReturnPending());
        $this->assertFalse(ReturnPendingJitHelper::returnPendingIsVoid());
        $this->assertSame(0, ReturnPendingJitHelper::takeReturnPending());

        ReturnPendingJitHelper::setReturnPending(100, false);
        $this->assertTrue(ReturnPendingJitHelper::hasReturnPending());
        $this->assertFalse(ReturnPendingJitHelper::returnPendingIsVoid());
        $this->assertSame(100, ReturnPendingJitHelper::takeReturnPending());
        $this->assertFalse(ReturnPendingJitHelper::hasReturnPending());

        ReturnPendingJitHelper::setReturnPending(0, true);
        $this->assertTrue(ReturnPendingJitHelper::hasReturnPending());
        $this->assertTrue(ReturnPendingJitHelper::returnPendingIsVoid());
        $this->assertSame(0, ReturnPendingJitHelper::takeReturnPending());
    }
}
