<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\posix\VmPosixSessionPure;
use PHPUnit\Framework\TestCase;

/** posix_getsid()/posix_getpgid() pure /proc path without libc FFI (#12673). */
final class VmPosixSessionPureRuntimeShrinkTest extends TestCase
{
    public function testVmPosixGetsidGetpgidUsePureProcPathNotLibcFfi(): void
    {
        $vmPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringContainsString('VmPosixSessionPure::getsid', $vmPosix);
        $this->assertStringContainsString('VmPosixSessionPure::getpgid', $vmPosix);
        $this->assertDoesNotMatchRegularExpression('/pid_t getsid\\(/', $vmPosix);
        $this->assertDoesNotMatchRegularExpression('/pid_t getpgid\\(/', $vmPosix);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosixSessionPure.php');
        $this->assertStringContainsString('/proc/self/sessionid', $pure);
        $this->assertStringContainsString('/proc/self/stat', $pure);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testPosixGetsidGetpgidMatchPureOnLinux(): void
    {
        if (!VmPosixSessionPure::available()) {
            $this->markTestSkipped('Linux /proc sessionid only');
        }

        $sid = VmPosixSessionPure::getsid(0);
        $this->assertIsInt($sid);
        $this->assertSame($sid, VmPosix::getsid(0));

        $pgid = VmPosixSessionPure::getpgid(0);
        $this->assertIsInt($pgid);
        $this->assertSame($pgid, VmPosix::getpgid(0));
    }

    public function testPosixGetsidWorksWithFfiDisabledOnLinux(): void
    {
        if (!VmPosixSessionPure::available()) {
            $this->markTestSkipped('Linux /proc sessionid only');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $sid = VmPosix::getsid(0);
            $this->assertIsInt($sid);
            $this->assertGreaterThanOrEqual(0, $sid);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
