<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHrtime;
use PHPCompiler\ext\standard\VmHrtimeNative;
use PHPUnit\Framework\TestCase;

/** @covers issue #7315 */
final class VmHrtimeNativeTest extends TestCase
{
    public function testVmHrtimeDoesNotUseHostFfi(): void
    {
        $src = (string) \file_get_contents(__DIR__.'/../../ext/standard/VmHrtime.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\FFI::/', $src);
        $this->assertDoesNotMatchRegularExpression('/extension_loaded\\(\\s*[\'"]ffi[\'"]\\s*\\)/', $src);
    }

    public function testReadMonotonicLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/uptime')) {
            $this->markTestSkipped('/proc/uptime unavailable');
        }
        [$sec, $nsec] = VmHrtimeNative::readMonotonic();
        $this->assertGreaterThan(0, $sec + $nsec);
    }
}
