<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\posix\VmPosixRlimitPure;
use PHPUnit\Framework\TestCase;

/** posix_getrlimit() pure /proc path without libc getrlimit FFI (#12426, #12442). */
final class VmPosixRlimitRuntimeShrinkTest extends TestCase
{
    public function testVmPosixGetrlimitUsesPureProcPathNotLibcGetrlimit(): void
    {
        $vmPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringContainsString('VmPosixRlimitPure::getrlimit()', $vmPosix);
        $this->assertStringNotContainsString('getrlimit(int resource', $vmPosix);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosixRlimitPure.php');
        $this->assertStringContainsString('/proc/self/limits', $pure);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testVmPosixFfiCdefDoesNotDeclareGetrlimitOrSetrlimit(): void
    {
        $vmPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertDoesNotMatchRegularExpression('/int getrlimit\\(/', $vmPosix);
        $this->assertDoesNotMatchRegularExpression('/int setrlimit\\(/', $vmPosix);

        $rlimitPure = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosixRlimitPure.php');
        $this->assertStringContainsString('PosixLibcThinAbi::setrlimit', $rlimitPure);
    }

    public function testPosixGetrlimitPureReturnsTwentyKeysOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !VmPosixRlimitPure::available()) {
            $this->markTestSkipped('Linux /proc/self/limits only');
        }

        $got = VmPosixRlimitPure::getrlimit();
        $this->assertIsArray($got);
        $this->assertCount(20, $got);

        $vm = VmPosix::getrlimit();
        $this->assertSame($got, $vm);
    }

    public function testPosixGetrlimitWorksWithFfiDisabledOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !VmPosixRlimitPure::available()) {
            $this->markTestSkipped('Linux /proc/self/limits only');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $vm = VmPosix::getrlimit();
            $this->assertCount(20, $vm);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
