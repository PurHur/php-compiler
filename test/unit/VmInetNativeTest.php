<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmInet;
use PHPCompiler\ext\standard\VmInetNative;
use PHPUnit\Framework\TestCase;

/** VmInetNative libc path without host \\ip2long() delegation (#3225 VM phase). */
final class VmInetNativeTest extends TestCase
{
    public function testVmInetPrefersNativeOverHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmInet.php');
        $this->assertStringContainsString('VmInetNative::available()', $source);
        $this->assertStringContainsString('VmInetNative::ip2long', $source);
        $this->assertStringContainsString('VmInetNative::long2ip', $source);
        $this->assertStringNotContainsString('delegates to Zend host', $source);
    }

    public function testNativeDefinesLibcInetFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmInetNative.php');
        $this->assertStringContainsString('int inet_aton(const char *cp', $source);
        $this->assertStringContainsString('int inet_pton(int af', $source);
        $this->assertStringContainsString('const char *inet_ntop', $source);
        $this->assertStringContainsString('$ffi->inet_aton', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\long2ip\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\inet_pton\\s*\\(/', $source);
    }

    public function testNativeInetConversionsOnLinux(): void
    {
        if (!VmInetNative::available()) {
            $this->markTestSkipped('FFI inet unavailable');
        }

        $this->assertSame('127.0.0.1', VmInetNative::long2ip(2130706433));
        $this->assertSame(2130706433, VmInetNative::ip2long('127.0.0.1'));
        $this->assertFalse(VmInetNative::long2ip(-1));
        $this->assertFalse(VmInetNative::ip2long('not-an-ip'));

        $v6 = VmInetNative::inet_pton('::1');
        $this->assertIsString($v6);
        $this->assertSame(16, strlen((string) $v6));
        $this->assertSame('::1', VmInetNative::inet_ntop((string) $v6));

        $v4 = VmInetNative::inet_pton('127.0.0.1');
        $this->assertIsString($v4);
        $this->assertSame(4, strlen((string) $v4));
        $this->assertSame('127.0.0.1', VmInetNative::inet_ntop((string) $v4));
    }

    public function testVmInetMatchesHostWhenFfiEnabled(): void
    {
        if (!VmInetNative::available()) {
            $this->markTestSkipped('FFI inet unavailable');
        }
        if (!\function_exists('ip2long') || !\function_exists('long2ip')) {
            $this->markTestSkipped('host inet builtins unavailable');
        }

        $this->assertSame(\long2ip(2130706433), VmInet::long2ip(2130706433));
        $this->assertSame(\ip2long('127.0.0.1'), VmInet::ip2long('127.0.0.1'));
        $bin6 = VmInet::inet_pton('::1');
        $this->assertSame(\inet_ntop((string) $bin6), VmInet::inet_ntop((string) $bin6));
    }

    public function testVmInetFallsBackWhenFfiDisabled(): void
    {
        if (!\function_exists('ip2long') || !\function_exists('long2ip')) {
            $this->markTestSkipped('host inet builtins unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertSame(\long2ip(2130706433), VmInet::long2ip(2130706433));
            $this->assertSame(\ip2long('127.0.0.1'), VmInet::ip2long('127.0.0.1'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
