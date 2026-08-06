<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmNetInterfaces;
use PHPCompiler\ext\standard\VmNetInterfacesNative;
use PHPCompiler\ext\standard\VmNetInterfacesPure;
use PHPUnit\Framework\TestCase;

/** VmNetInterfacesPure /sys path without getifaddrs FFI (#8988, #12353). */
final class VmNetInterfacesRuntimeShrinkTest extends TestCase
{
    public function testVmNetInterfacesNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmNetInterfacesNative.php');
        $this->assertStringContainsString('VmNetInterfacesPure::collect()', $source);
        $this->assertStringContainsString('VmNetInterfacesPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->getifaddrs/', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->freeifaddrs/', $source);
    }

    public function testStringNetInterfacesJitUsesHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNetInterfacesJit.php');
        $this->assertStringContainsString('NetInterfacesJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('resolveOk', $source);
        $this->assertStringContainsString('__hashtable__alloc', $source);
        $this->assertDoesNotMatchRegularExpression('/NetInterfacesJitHelper::resolve[^O]/', $source);
        $this->assertStringNotContainsString('ifa_next', $source);
        $this->assertStringNotContainsString('IFA_NAME', $source);
    }

    public function testNetInterfacesJitHelperExposesScalarAccessorsForThinAot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/NetInterfacesJitHelper.php');
        $this->assertStringContainsString('NestedJIT must not return', $source);
        $this->assertStringContainsString('function resolveOk', $source);
        $this->assertStringContainsString('function ifaceNameAt', $source);
        $this->assertStringContainsString('#26942', $source);
    }

    public function testNetGetInterfacesWorksWithFfiDisabledOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !VmNetInterfacesPure::available()) {
            $this->markTestSkipped('/sys/class/net unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $raw = VmNetInterfacesPure::collect();
            $this->assertIsArray($raw);
            $this->assertArrayHasKey('lo', $raw);
            $this->assertTrue($raw['lo']['up']);
            $this->assertNotEmpty($raw['lo']['unicast']);
            $found = false;
            $hasFamily = false;
            foreach ($raw['lo']['unicast'] as $entry) {
                if (isset($entry['family'])) {
                    $hasFamily = true;
                }
                if (($entry['address'] ?? '') === '127.0.0.1') {
                    $found = true;
                }
            }
            $this->assertTrue($hasFamily, 'unicast entries must include family (#23715)');
            $this->assertTrue($found);

            $this->assertTrue(VmNetInterfacesNative::available());
            $this->assertSame($raw, VmNetInterfacesNative::collect());

            $ht = VmNetInterfaces::get();
            $this->assertNotFalse($ht);
            // php-src getifaddrs-ish: lo before other ifaces (#28140)
            $rawOrdered = VmNetInterfacesPure::collect();
            $this->assertIsArray($rawOrdered);
            $this->assertSame('lo', array_key_first($rawOrdered));
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testNativeCollectMatchesPureOnLinux(): void
    {
        if (!VmNetInterfacesPure::available()) {
            $this->markTestSkipped('net interfaces pure path unavailable');
        }

        $pure = VmNetInterfacesPure::collect();
        $native = VmNetInterfacesNative::collect();
        $this->assertIsArray($pure);
        $this->assertSame($pure, $native);
        $this->assertArrayHasKey('lo', $pure);
    }
}
