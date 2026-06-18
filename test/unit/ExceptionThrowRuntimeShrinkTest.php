<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExceptionJitHelper;
use PHPUnit\Framework\TestCase;

/** Exception throw-pending JIT bridge uses PHP SSOT (#9632). */
final class ExceptionThrowRuntimeShrinkTest extends TestCase
{
    public function testJitThrowDelegatesToExceptionThrowRuntimeOnJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JitThrow.php');
        $this->assertStringContainsString('ExceptionThrowRuntime::implement', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
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
