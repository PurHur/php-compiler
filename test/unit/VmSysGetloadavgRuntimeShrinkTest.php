<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SysGetloadavgJitHelper;
use PHPCompiler\ext\standard\VmSys;
use PHPCompiler\ext\standard\VmSysGetloadavgNative;
use PHPCompiler\ext\standard\VmSysGetloadavgPure;
use PHPUnit\Framework\TestCase;

/** sys_getloadavg() pure /proc path without libc getloadavg FFI (#12106, php-in-php). */
final class VmSysGetloadavgRuntimeShrinkTest extends TestCase
{
    public function testVmSysGetloadavgUsesPureBackendWithoutHostDelegation(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetloadavgNative.php');
        $this->assertStringContainsString('VmSysGetloadavgPure::getLoadavg', $native);
        $this->assertStringNotContainsString('\\FFI', $native);
        $this->assertStringNotContainsString('$ffi->getloadavg', $native);
        $this->assertStringNotContainsString('int getloadavg', $native);
        $this->assertDoesNotMatchRegularExpression('/\\\\sys_getloadavg\\s*\\(/', $native);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetloadavgPure.php');
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
            $this->markTestSkipped('/proc/loadavg unavailable');
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
            $this->markTestSkipped('/proc/loadavg unavailable');
        }

        $avg = VmSysGetloadavgNative::getLoadavg();
        $this->assertIsArray($avg);
        $this->assertCount(3, $avg);
        foreach ($avg as $load) {
            $this->assertIsFloat($load);
            $this->assertGreaterThanOrEqual(0.0, $load);
        }
    }
}
