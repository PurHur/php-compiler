<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\InetJitHelper;
use PHPCompiler\ext\standard\VmInet;
use PHPCompiler\ext\standard\VmInetPure;
use PHPUnit\Framework\TestCase;

/** InetRuntime must route through InetJitHelper PHP, not libc LLVM (#8969). */
final class InetRuntimeShrinkTest extends TestCase
{
    public function testInetRuntimeUsesJitHelperNotLibcLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/InetRuntime.php');
        $this->assertStringContainsString('InetJitHelper', $source);
        $this->assertStringNotContainsString('InetLibcBridge', $source);
        $this->assertStringNotContainsString("lookupFunction('inet_pton')", $source);
        $this->assertStringNotContainsString("lookupFunction('inet_ntoa')", $source);
        $this->assertStringNotContainsString("lookupFunction('sscanf')", $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertLessThan(360, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/InetLibcBridge.php');
    }

    public function testInetJitHelperDelegatesToVmInet(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/InetJitHelper.php');
        $this->assertStringContainsString('VmInet::ip2long', $source);
        $this->assertStringContainsString('VmInet::long2ip', $source);
        $this->assertStringContainsString('VmInet::inet_pton', $source);
        $this->assertStringContainsString('VmInet::inet_ntop', $source);
    }

    public function testVmInetUsesPurePathByDefault(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmInet.php');
        $this->assertStringContainsString('VmInetPure::', $source);
        $this->assertStringNotContainsString('VmInetNative::available()', $source);
    }

    public function testInetJitHelperMatchesVmInet(): void
    {
        InetJitHelper::resetForTest();
        $this->assertSame(1, InetJitHelper::ip2longTag('127.0.0.1'));
        $this->assertSame(2130706433, InetJitHelper::lastInt());

        InetJitHelper::resetForTest();
        $this->assertSame(2, InetJitHelper::long2ipTag(2130706433));
        $this->assertSame('127.0.0.1', InetJitHelper::lastString());

        $bin6 = VmInet::inet_pton('::1');
        $this->assertIsString($bin6);
        $this->assertSame('::1', VmInet::inet_ntop((string) $bin6));
        $this->assertSame($bin6, InetJitHelper::inetPton('::1'));
        $this->assertSame('::1', InetJitHelper::inetNtop((string) $bin6));
    }

    public function testVmInetMatchesPureWithoutFfi(): void
    {
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertSame(VmInetPure::long2ip(2130706433), VmInet::long2ip(2130706433));
            $this->assertSame(VmInetPure::ip2long('127.0.0.1'), VmInet::ip2long('127.0.0.1'));
            $bin6 = VmInet::inet_pton('::1');
            $this->assertIsString($bin6);
            $this->assertSame('::1', VmInet::inet_ntop((string) $bin6));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
