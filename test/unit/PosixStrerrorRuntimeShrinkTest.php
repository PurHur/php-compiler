<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixStrerrorJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\posix\VmPosixStrerrorPure;
use PHPUnit\Framework\TestCase;

/** posix_strerror VM/JIT routes through VmPosixStrerrorPure not libc strerror FFI (#12477). */
final class PosixStrerrorRuntimeShrinkTest extends TestCase
{
    public function testVmPosixStrerrorUsesPureMapNotLibcFfi(): void
    {
        $vmPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringContainsString('VmPosixStrerrorPure::message', $vmPosix);
        $this->assertStringNotContainsString('strerror(int errnum', $vmPosix);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosixStrerrorPure.php');
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testPosixStrerrorRuntimeUsesJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixStrerrorRuntime.php');
        $this->assertStringContainsString('PosixStrerrorJitHelper', $runtime);
        $this->assertStringContainsString('strerrorStandalone', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $jitPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixStrerrorRuntime::strerror', $jitPosix);
    }

    public function testPosixStrerrorPureMatchesZendOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Linux errno map only');
        }

        foreach ([1, 2, 13, 22, 28] as $errno) {
            $expected = \posix_strerror($errno);
            $this->assertSame($expected, VmPosixStrerrorPure::message($errno));
            $this->assertSame($expected, PosixStrerrorJitHelper::message($errno));
            $this->assertSame($expected, VmPosix::strerror($errno));
        }
    }

    public function testPosixStrerrorWorksWithFfiDisabledOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Linux only');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertSame('No such file or directory', VmPosix::strerror(2));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
