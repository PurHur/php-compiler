<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmInet;
use PHPCompiler\ext\standard\VmInetNative;
use PHPCompiler\ext\standard\VmInetPure;
use PHPUnit\Framework\TestCase;

/** VmInet libc + pure-PHP path without host \\ip2long() delegation (#3225, #7929, #12354). */
final class VmInetNativeTest extends TestCase
{
    public function testVmInetDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmInet.php');
        $this->assertStringContainsString('VmInetPure::', $source);
        $this->assertStringNotContainsString('VmInetNative::available()', $source);
        $this->assertStringNotContainsString('host Zend fallback', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\long2ip\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\ip2long\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\inet_ntop\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\inet_pton\\s*\\(/', $source);
    }

    public function testNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmInetNative.php');
        $this->assertStringContainsString('VmInetPure::long2ip', $source);
        $this->assertStringContainsString('VmInetPure::ip2long', $source);
        $this->assertStringContainsString('VmInetPure::inet_ntop', $source);
        $this->assertStringContainsString('VmInetPure::inet_pton', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->inet_pton/', $source);
        $this->assertDoesNotMatchRegularExpression('/PHP_COMPILER_INET_FFI/', $source);
    }

    public function testPureInetConversions(): void
    {
        $this->assertSame('127.0.0.1', VmInetPure::long2ip(2130706433));
        $this->assertSame(2130706433, VmInetPure::ip2long('127.0.0.1'));
        $this->assertFalse(VmInetPure::long2ip(-1));
        $this->assertFalse(VmInetPure::ip2long('not-an-ip'));
        $this->assertFalse(VmInetPure::ip2long('01.02.03.04'));
        $this->assertSame(4294967295, VmInetPure::ip2long('255.255.255.255'));

        $v6 = VmInetPure::inet_pton('::1');
        $this->assertIsString($v6);
        $this->assertSame(16, \strlen((string) $v6));
        $this->assertSame('::1', VmInetPure::inet_ntop((string) $v6));

        $v4 = VmInetPure::inet_pton('127.0.0.1');
        $this->assertIsString($v4);
        $this->assertSame(4, \strlen((string) $v4));
        $this->assertSame('127.0.0.1', VmInetPure::inet_ntop((string) $v4));
    }

    public function testNativeInetMatchesPure(): void
    {
        $this->assertTrue(VmInetNative::available());

        $this->assertSame('127.0.0.1', VmInetNative::long2ip(2130706433));
        $this->assertSame(2130706433, VmInetNative::ip2long('127.0.0.1'));
        $this->assertFalse(VmInetNative::long2ip(-1));
        $this->assertFalse(VmInetNative::ip2long('not-an-ip'));
        $this->assertFalse(VmInetNative::ip2long('01.02.03.04'));

        $v6 = VmInetNative::inet_pton('::1');
        $this->assertIsString($v6);
        $this->assertSame(16, \strlen((string) $v6));
        $this->assertSame('::1', VmInetNative::inet_ntop((string) $v6));

        $v4 = VmInetNative::inet_pton('127.0.0.1');
        $this->assertIsString($v4);
        $this->assertSame(4, \strlen((string) $v4));
        $this->assertSame('127.0.0.1', VmInetNative::inet_ntop((string) $v4));
    }

    public function testVmInetUsesPureFallbackWhenFfiDisabled(): void
    {
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertSame('127.0.0.1', VmInet::long2ip(2130706433));
            $this->assertSame(2130706433, VmInet::ip2long('127.0.0.1'));
            $this->assertFalse(VmInet::ip2long('01.02.03.04'));
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
