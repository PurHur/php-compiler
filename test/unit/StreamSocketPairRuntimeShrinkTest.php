<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\StreamSocketPairJitHelper;
use PHPCompiler\ext\standard\VmStreamSocketPairNative;
use PHPUnit\Framework\TestCase;

/** StreamSocketPairJit routes through StreamSocketPairJitHelper PHP not libc LLVM (#13710). */
final class StreamSocketPairRuntimeShrinkTest extends TestCase
{
    public function testStreamSocketPairJitIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSocketPairJit.php');
        $this->assertStringContainsString('StreamSocketPairRuntime', $source);
        $this->assertStringNotContainsString('lookupFunction(\'socketpair\')', $source);
        $this->assertStringNotContainsString('emitStreamSocketPair', $source);
        $this->assertLessThan(25, \substr_count($source, "\n") + 1);
    }

    public function testStreamSocketPairRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSocketPairRuntime.php');
        $this->assertStringContainsString('StreamSocketPairJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('lookupFunction(\'socketpair\')', $source);
        $this->assertStringNotContainsString('emitStreamSocketPair(', $source);
    }

    public function testStreamSocketPairJitHelperDelegatesToVmNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamSocketPairJitHelper.php');
        $this->assertStringContainsString('VmStreamSocketPairNative::pair', $source);
    }

    public function testStreamSocketPairJitHelperMatchesVmNative(): void
    {
        if (!VmStreamSocketPairNative::available()) {
            self::markTestSkipped('stream_socket_pair unavailable');
        }

        $domain = StdlibConstants::STREAM_PF_UNIX;
        $type = StdlibConstants::STREAM_SOCK_STREAM;
        $protocol = StdlibConstants::STREAM_IPPROTO_IP;

        $native = VmStreamSocketPairNative::pair($domain, $type, $protocol);
        $this->assertIsArray($native);

        $jit = StreamSocketPairJitHelper::pairArgv($domain, $type, $protocol);
        $this->assertNotNull($jit);
        $this->assertSame(2, $jit->getNumElements());
    }
}
