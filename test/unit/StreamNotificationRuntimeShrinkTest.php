<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamNotificationJitHelper;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/**
 * StreamNotificationRuntime routes through StreamNotificationJitHelper PHP via
 * JitVmHelperLink::ensureCompiled (#9478 / #25223 / peer #25183 / #25139).
 */
final class StreamNotificationRuntimeShrinkTest extends TestCase
{
    public function testStreamNotificationRuntimeUsesJitHelperNotLlvmGlobal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamNotificationRuntime.php');
        $this->assertStringContainsString('StreamNotificationJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('addGlobal($valPtr', $source);
        $this->assertStringNotContainsString('GLOBAL_CALLBACK', $source);
        // Empty probe must be reused — bare addFunction after getNamedFunction mints .1 (#33650).
        $this->assertStringContainsString('null !== $probe', $source);
        $this->assertStringContainsString('? $probe', $source);
        $this->assertStringContainsString('getNamedFunction(\'__phpc_stream_notification_callback_set\')', $source);
        $this->assertLessThan(140, \substr_count($source, "\n") + 1);
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
