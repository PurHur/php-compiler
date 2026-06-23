<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamNotificationJitHelper;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** StreamNotificationRuntime routes through StreamNotificationJitHelper PHP not LLVM global (#9478). */
final class StreamNotificationRuntimeShrinkTest extends TestCase
{
    public function testStreamNotificationRuntimeUsesJitHelperNotLlvmGlobal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamNotificationRuntime.php');
        $this->assertStringContainsString('StreamNotificationJitHelper', $source);
        $this->assertStringNotContainsString('addGlobal($valPtr', $source);
        $this->assertStringNotContainsString('GLOBAL_CALLBACK', $source);
        $this->assertLessThan(170, \substr_count($source, "\n") + 1);
    }

    public function testStreamNotificationJitHelperDelegatesStorage(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamNotificationJitHelper.php');
        $this->assertStringContainsString('VmStreamNotification::requireValidCallback', $source);
    }

    public function testStreamNotificationJitHelperSetGlobalRoundTrip(): void
    {
        $cb = new VMVariable();
        $cb->string('strlen');
        $prev = StreamNotificationJitHelper::setGlobal($cb);
        $this->assertSame(VMVariable::TYPE_NULL, $prev->resolveIndirect()->type);

        $stored = StreamNotificationJitHelper::globalCallback();
        $this->assertNotNull($stored);
        $this->assertSame(VMVariable::TYPE_STRING, $stored->resolveIndirect()->type);

        $nullArg = new VMVariable();
        $nullArg->null();
        $cleared = StreamNotificationJitHelper::setGlobal($nullArg);
        $this->assertSame(VMVariable::TYPE_STRING, $cleared->resolveIndirect()->type);
        $this->assertNull(StreamNotificationJitHelper::globalCallback());
    }
}
