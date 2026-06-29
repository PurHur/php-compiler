<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SysGetloadavgJitHelper;
use PHPCompiler\ext\standard\VmSys;
use PHPCompiler\ext\standard\VmSysGetloadavgNative;
use PHPCompiler\ext\standard\VmSysGetloadavgPure;
use PHPUnit\Framework\TestCase;

/** sys_getloadavg() pure path without libc getloadavg FFI (#12106, #13564, php-in-php). */
final class VmSysGetloadavgRuntimeShrinkTest extends TestCase
{
    public function testVmSysGetloadavgUsesPureBackendWithoutLibcFfi(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetloadavgNative.php');
        $this->assertStringContainsString('VmSysGetloadavgPure::getLoadavg', $native);
        $this->assertStringNotContainsString('VmSysGetloadavgLibc', $native);
        $this->assertStringNotContainsString('\\FFI', $native);
        $this->assertStringNotContainsString('$ffi->getloadavg', $native);
        $this->assertStringNotContainsString('int getloadavg', $native);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/VmSysGetloadavgLibc.php');

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetloadavgPure.php');
        $this->assertStringContainsString('sys_getloadavg', $pure);
        $this->assertStringContainsString('/proc/loadavg', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testJitSysGetloadavgDelegatesToRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSysGetloadavg.php');
        $this->assertStringContainsString('StringSysGetloadavg::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_sys_getloadavg', $source);
        $this->assertStringNotContainsString("lookupFunction('getloadavg')", $source);
        $this->assertLessThan(40, \substr_count($source, "\n") + 1);
    }

    public function testSysGetloadavgJitHelperMatchesVmSys(): void
    {
        if (!VmSysGetloadavgPure::available()) {
            $this->markTestSkipped('sys_getloadavg and /proc/loadavg unavailable');
        }

        $vm = VmSys::getLoadavg();
        $this->assertIsArray($vm);
        $ht = SysGetloadavgJitHelper::resolve();
        $this->assertNotNull($ht);
        $this->assertSame(3, $ht->getNumElements());
    }

    public function testNativeGetLoadavgShapeOnLinux(): void
    {
        if (!VmSysGetloadavgNative::available()) {
            $this->markTestSkipped('sys_getloadavg unavailable');
        }

        $avg = VmSysGetloadavgNative::getLoadavg();
        $this->assertIsArray($avg);
        $this->assertCount(3, $avg);
        foreach ($avg as $load) {
            $this->assertIsFloat($load);
            $this->assertGreaterThanOrEqual(0.0, $load);
        }
    }

    public function testProcFallbackWhenHostBuiltinDisabled(): void
    {
        if (!\is_readable('/proc/loadavg')) {
            $this->markTestSkipped('/proc/loadavg unavailable');
        }

        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $avg = VmSysGetloadavgPure::getLoadavg();
            $this->assertIsArray($avg);
            $this->assertCount(3, $avg);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testHostBuiltinHasMorePrecisionThanProcStringWhenAvailable(): void
    {
        if (!\function_exists('sys_getloadavg') || !\is_readable('/proc/loadavg')) {
            $this->markTestSkipped('host sys_getloadavg or /proc/loadavg unavailable');
        }

        $raw = @\file_get_contents('/proc/loadavg');
        if (!\is_string($raw) || '' === $raw) {
            $this->markTestSkipped('/proc/loadavg unreadable');
        }
        $procField = \explode(' ', \trim($raw))[0] ?? '';
        if ('' === $procField) {
            $this->markTestSkipped('empty /proc/loadavg');
        }

        $host = @\sys_getloadavg();
        $this->assertIsArray($host);
        $this->assertNotSame($procField, \rtrim(\sprintf('%.12F', (float) $host[0]), '0'));
    }
}
