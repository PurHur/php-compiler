<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\StreamSocketPairJitHelper;
use PHPCompiler\ext\standard\VmStreamSocketPairNative;
use PHPUnit\Framework\TestCase;

/**
 * StreamSocketPairJit routes through StreamSocketPairJitHelper PHP not libc LLVM (#13710).
 * No inventory null-stub fork — always NestedJIT (#21082, peer #20943 / #20966).
 */
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

    public function testStreamSocketPairRuntimeUsesJitHelperAlwaysNoInventoryStub(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSocketPairRuntime.php');
        $this->assertStringContainsString('StreamSocketPairJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('lookupFunction(\'socketpair\')', $source);
        $this->assertStringNotContainsString('emitStreamSocketPair(', $source);
        $this->assertStringNotContainsString('shouldDeferInventoryEmit', $source);
        $this->assertStringNotContainsString('implementStub', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', $source);
        $this->assertStringNotContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $source);
        $this->assertStringNotContainsString('stream_socket_pair_stub_entry', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
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
