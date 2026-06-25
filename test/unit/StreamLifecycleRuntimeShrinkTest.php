<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamLifecycleJitHelper;
use PHPCompiler\ext\standard\StreamLibcHandleJitHelper;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPUnit\Framework\TestCase;

/** StreamLifecycleJit embed routes through StreamLifecycleJitHelper PHP not LLVM monolith (#9442). */
final class StreamLifecycleRuntimeShrinkTest extends TestCase
{
    public function testStreamLifecycleJitIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamLifecycleJit.php');
        $this->assertStringContainsString('StreamLifecycleRuntime', $source);
        $this->assertStringContainsString('StreamLifecycleStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitIsResource', $source);
        $this->assertStringNotContainsString('emitFclose', $source);
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }

    public function testStreamLifecycleRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamLifecycleRuntime.php');
        $this->assertStringContainsString('StreamLifecycleJitHelper', $source);
        $this->assertStringNotContainsString('loadTableSlot', $source);
    }

    public function testStreamLifecycleJitHelperDelegatesToVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamLifecycleJitHelper.php');
        $this->assertStringContainsString('VmFs::fclose', $source);
        $this->assertStringContainsString('VmFs::feof', $source);
        $this->assertStringContainsString('VmDir::isValidHandle', $source);
        $this->assertStringContainsString('StreamLibcHandleJitHelper', $source);
    }

    public function testStreamLifecycleJitHelperVmMemoryRoundTrip(): void
    {
        $handle = VmPhpMemoryStream::open('php://memory', 'w+b');
        $this->assertNotFalse($handle);
        $this->assertSame(1, StreamLifecycleJitHelper::isResourceArgv((int) $handle));
        $this->assertSame(0, StreamLifecycleJitHelper::feofArgv((int) $handle));
        $this->assertSame(1, StreamLifecycleJitHelper::fcloseArgv((int) $handle));
        $this->assertSame(0, StreamLifecycleJitHelper::isResourceArgv((int) $handle));
    }

    public function testStreamLibcHandleJitHelperRegisterRoundTrip(): void
    {
        StreamLibcHandleJitHelper::resetForTest();
        $this->assertSame(0, StreamLibcHandleJitHelper::resolvePtr(7));
        StreamLibcHandleJitHelper::registerFromPtr(7, 0x1234);
        $this->assertSame(0x1234, StreamLibcHandleJitHelper::resolvePtr(7));
        StreamLibcHandleJitHelper::registerFromPtr(7, 0);
        $this->assertSame(0, StreamLibcHandleJitHelper::resolvePtr(7));
    }

    public function testStreamIoJitMirrorsHandleRegistrationOnEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('StreamLibcHandleRuntime::emitRegisterHandle', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
    }
}
