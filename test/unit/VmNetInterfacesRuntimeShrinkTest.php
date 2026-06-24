<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmNetInterfaces;
use PHPCompiler\ext\standard\VmNetInterfacesNative;
use PHPCompiler\ext\standard\VmNetInterfacesPure;
use PHPUnit\Framework\TestCase;

/** VmNetInterfacesPure /sys path without getifaddrs FFI (#8988). */
final class VmNetInterfacesRuntimeShrinkTest extends TestCase
{
    public function testVmNetInterfacesNativePrefersPurePath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmNetInterfacesNative.php');
        $this->assertStringContainsString('VmNetInterfacesPure::collect()', $source);
        $this->assertMatchesRegularExpression(
            '/public static function collect\(\)[^{]*\{[^}]*VmNetInterfacesPure::collect/s',
            $source
        );
    }

    public function testStringNetInterfacesJitUsesHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNetInterfacesJit.php');
        $this->assertStringContainsString('NetInterfacesJitHelper', $source);
        $this->assertStringNotContainsString('ifa_next', $source);
        $this->assertStringNotContainsString('IFA_NAME', $source);
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
            $found = false;
            foreach ($raw['lo']['unicast'] as $entry) {
                if (($entry['address'] ?? '') === '127.0.0.1') {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found);

            $ht = VmNetInterfaces::get();
            $this->assertNotFalse($ht);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testPureCollectMatchesNativeOnLinux(): void
    {
        if (!VmNetInterfacesNative::available() || !VmNetInterfacesPure::available()) {
            $this->markTestSkipped('net interfaces paths unavailable');
        }

        $pure = VmNetInterfacesPure::collect();
        $native = VmNetInterfacesNative::collect();
        $this->assertIsArray($pure);
        $this->assertIsArray($native);
        $this->assertArrayHasKey('lo', $pure);
        $this->assertArrayHasKey('lo', $native);
        $this->assertSame($native['lo']['up'], $pure['lo']['up']);
    }
}
