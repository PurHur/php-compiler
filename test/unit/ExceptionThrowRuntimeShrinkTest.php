<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExceptionJitHelper;
use PHPUnit\Framework\TestCase;

/** Exception throw-pending JIT bridge uses PHP SSOT (#9632, #9679). */
final class ExceptionThrowRuntimeShrinkTest extends TestCase
{
    public function testJitThrowRoutesStandaloneAndEmbedThroughExceptionThrowRuntime(): void
    {
        $jitThrow = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JitThrow.php');
        $this->assertStringContainsString('ExceptionThrowRuntime::implement', $jitThrow);
        $this->assertStringNotContainsString('phpc_jit_throw_flag', $jitThrow);
        $this->assertStringNotContainsString('implementPendingHelpers', $jitThrow);
        $this->assertStringNotContainsString('registerPendingGlobals', $jitThrow);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $jitThrow);
    }

    public function testExceptionThrowRuntimeIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ExceptionThrowRuntime.php');
        $this->assertStringContainsString('JitHelperAbiBridge::implement', $source);
        $this->assertStringContainsString('ExceptionJitHelper', $source);
        $lineCount = substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(70, $lineCount, 'ExceptionThrowRuntime should be a thin trampoline (#9679)');
    }

    public function testExceptionJitHelperPendingThrowLifecycle(): void
    {
        ExceptionJitHelper::clearThrowPending();
        ExceptionJitHelper::clearActiveCatch();
        $this->assertFalse(ExceptionJitHelper::hasThrowPending());
        $this->assertSame(0, ExceptionJitHelper::takeThrowPending());

        ExceptionJitHelper::setThrowPending(42);
        $this->assertTrue(ExceptionJitHelper::hasThrowPending());
        $this->assertSame(42, ExceptionJitHelper::takeThrowPending());
        $this->assertFalse(ExceptionJitHelper::hasThrowPending());
        $this->assertSame(0, ExceptionJitHelper::takeThrowPending());

        ExceptionJitHelper::setActiveCatch(99);
        $this->assertSame(99, ExceptionJitHelper::getActiveCatch());
        ExceptionJitHelper::clearActiveCatch();
        $this->assertSame(0, ExceptionJitHelper::getActiveCatch());
    }
}
