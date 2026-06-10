<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmSys;
use PHPCompiler\ext\standard\VmSysGetloadavgNative;
use PHPUnit\Framework\TestCase;

/** VmSysGetloadavgNative libc path without host \\sys_getloadavg() delegation (#4607). */
final class VmSysGetloadavgNativeTest extends TestCase
{
    public function testVmSysDoesNotReferenceHostSysGetloadavg(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSys.php');
        $this->assertStringContainsString('VmSysGetloadavgNative::getLoadavg', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\sys_getloadavg\\s*\\(/', $source);
        $this->assertStringNotContainsString('function_exists(\'sys_getloadavg\')', $source);
    }

    public function testNativeDefinesLibcGetloadavgFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetloadavgNative.php');
        $this->assertStringContainsString('int getloadavg(double loadavg[]', $source);
        $this->assertStringContainsString('$ffi->getloadavg', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\sys_getloadavg\\s*\\(/', $source);
    }

    public function testNativeGetLoadavgShapeOnLinux(): void
    {
        if (!VmSysGetloadavgNative::available()) {
            $this->markTestSkipped('FFI getloadavg unavailable');
        }

        $avg = VmSysGetloadavgNative::getLoadavg();
        $this->assertIsArray($avg);
        $this->assertCount(3, $avg);
        foreach ($avg as $load) {
            $this->assertIsFloat($load);
            $this->assertGreaterThanOrEqual(0.0, $load);
        }
    }

    public function testVmSysGetLoadavgMatchesNative(): void
    {
        if (!VmSysGetloadavgNative::available()) {
            $this->markTestSkipped('FFI getloadavg unavailable');
        }

        $avg = VmSys::getLoadavg();
        $this->assertIsArray($avg);
        $this->assertCount(3, $avg);
    }
}
