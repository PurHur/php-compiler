<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixConstants;
use PHPCompiler\ext\posix\PosixLibcThinAbi;
use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\posix\VmPosixIdentityWritePure;
use PHPCompiler\ext\posix\VmPosixMknodPure;
use PHPCompiler\ext\posix\VmPosixRlimitPure;
use PHPCompiler\ext\posix\VmPosixSessionPure;
use PHPUnit\Framework\TestCase;

/** VmPosix libc FFI quarantined to PosixLibcThinAbi — VM routes through *Pure SSOT (#12733). */
final class VmPosixLibcThinRuntimeShrinkTest extends TestCase
{
    public function testVmPosixHasNoLibcFfiCdef(): void
    {
        $vmPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringNotContainsString('FFI::cdef', $vmPosix);
        $this->assertStringNotContainsString('private static function ffi(', $vmPosix);
        $this->assertStringContainsString('PosixLibcThinAbi', $vmPosix);
        $this->assertStringContainsString('VmPosixMknodPure::mknod', $vmPosix);
        $this->assertStringContainsString('VmPosixIdentityWritePure::setuid', $vmPosix);
        $this->assertStringContainsString('VmPosixRlimitPure::setrlimit', $vmPosix);
        $this->assertStringContainsString('VmPosixSessionPure::setsid', $vmPosix);
        $this->assertStringContainsString('VmPosixSessionPure::setpgid', $vmPosix);
    }

    public function testPosixLibcThinAbiDocumentsWriteSyscalls(): void
    {
        $abi = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixLibcThinAbi.php');
        $this->assertStringContainsString('FFI::cdef', $abi);
        $this->assertStringContainsString('int mknod(', $abi);
        $this->assertStringContainsString('int setrlimit(', $abi);
        $this->assertStringContainsString('pid_t setsid(', $abi);
        $this->assertStringContainsString('int setpgid(', $abi);
        $this->assertStringContainsString('int setuid(', $abi);
        $this->assertStringContainsString('clock_t times(', $abi);
        $this->assertStringContainsString('systemClockTicks', $abi);
    }

    public function testPureHelpersDelegateToThinAbiNotVmPosixFfi(): void
    {
        foreach ([
            'VmPosixRlimitPure.php',
            'VmPosixSessionPure.php',
            'VmPosixMknodPure.php',
            'VmPosixIdentityWritePure.php',
        ] as $file) {
            $source = (string) file_get_contents(__DIR__.'/../../ext/posix/'.$file);
            $this->assertStringContainsString('PosixLibcThinAbi', $source, $file);
            $this->assertStringNotContainsString('FFI::cdef', $source, $file);
        }
    }

    public function testPosixGetrlimitAndSetrlimitSplitReadWriteSsot(): void
    {
        $rlimit = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosixRlimitPure.php');
        $this->assertStringContainsString('/proc/self/limits', $rlimit);
        $this->assertStringContainsString('PosixLibcThinAbi::setrlimit', $rlimit);
    }

    public function testPosixSetegidRestoreMatchesHostOnLinux(): void
    {
        if (!PosixLibcThinAbi::available() || !\function_exists('posix_getegid')) {
            $this->markTestSkipped('libc thin ABI or host posix unavailable');
        }

        $egid = (int) \posix_getegid();
        $this->assertSame(
            (bool) @\posix_setegid($egid),
            VmPosix::setegid($egid)
        );
    }

    public function testPosixSetrlimitRoundTripSoftOpenfilesOnLinux(): void
    {
        if (!PosixLibcThinAbi::available() || !VmPosixRlimitPure::available()) {
            $this->markTestSkipped('Linux procfs + libc thin ABI only');
        }

        $before = VmPosix::getrlimit();
        $soft = $before['soft openfiles'];
        $hard = $before['hard openfiles'];
        if (!\is_int($soft) || !\is_int($hard)) {
            $this->markTestSkipped('openfiles limits not numeric');
        }

        $this->assertTrue(VmPosix::setrlimit(PosixConstants::RLIMIT_NOFILE, $soft, $hard));
        $after = VmPosix::getrlimit();
        $this->assertSame($soft, $after['soft openfiles']);
        $this->assertSame($hard, $after['hard openfiles']);
    }

    public function testPosixWritePathsFailClosedWhenFfiDisabledOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Linux only');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertFalse(VmPosixMknodPure::available());
            $this->assertFalse(VmPosixIdentityWritePure::available());
            $this->assertNull(VmPosixSessionPure::setsid());
            $this->assertNull(VmPosixRlimitPure::setrlimit(0, 1, 1));

            $this->expectException(\Error::class);
            VmPosix::setuid(0);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
