<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmSys;
use PHPCompiler\ext\standard\VmSysGetloadavgNative;
use PHPUnit\Framework\TestCase;

/** VmSysGetloadavgNative /proc path without host \\sys_getloadavg() delegation (#4607, #12106). */
final class VmSysGetloadavgNativeTest extends TestCase
{
    public function testVmSysDoesNotReferenceHostSysGetloadavg(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSys.php');
        $this->assertStringContainsString('VmSysGetloadavgNative::getLoadavg', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\sys_getloadavg\\s*\\(/', $source);
        $this->assertStringNotContainsString('function_exists(\'sys_getloadavg\')', $source);
    }

    public function testNativeDelegatesToPureProcLoadavg(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetloadavgNative.php');
        $this->assertStringContainsString('VmSysGetloadavgPure', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\sys_getloadavg\\s*\\(/', $source);
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

    public function testVmSysGetLoadavgMatchesNative(): void
    {
        if (!VmSysGetloadavgNative::available()) {
            $this->markTestSkipped('/proc/loadavg unavailable');
        }

        $avg = VmSys::getLoadavg();
        $this->assertIsArray($avg);
        $this->assertCount(3, $avg);
    }
}
