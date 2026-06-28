<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixCtermidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\posix\VmPosixCtermidPure;
use PHPUnit\Framework\TestCase;

/** posix_ctermid VM/JIT routes through VmPosixCtermidPure not libc ctermid FFI (#12684). */
final class PosixCtermidRuntimeShrinkTest extends TestCase
{
    public function testVmPosixCtermidUsesPurePathNotLibcFfi(): void
    {
        $vmPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertStringContainsString('VmPosixCtermidPure::path', $vmPosix);
        $this->assertDoesNotMatchRegularExpression('/char \\*ctermid\\(/', $vmPosix);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/posix/VmPosixCtermidPure.php');
        $this->assertStringContainsString('/dev/tty', $pure);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testPosixCtermidRuntimeUsesJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixCtermidRuntime.php');
        $this->assertStringContainsString('PosixCtermidJitHelper', $runtime);
        $this->assertStringNotContainsString('ctermidStandalone', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $jitPosix = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixCtermidRuntime::ctermid', $jitPosix);
        $this->assertStringNotContainsString('ctermidStandalone', $jitPosix);
        $this->assertStringNotContainsString("lookupFunction('ctermid')", $jitPosix);
    }

    public function testPosixCtermidPureMatchesZendOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Linux /dev/tty only');
        }

        $expected = \posix_ctermid();
        $this->assertSame($expected, VmPosixCtermidPure::path());
        $this->assertSame($expected, PosixCtermidJitHelper::path());
        $this->assertSame($expected, VmPosix::ctermid());
    }

    public function testPosixCtermidWorksWithFfiDisabledOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Linux only');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertSame(\posix_ctermid(), VmPosix::ctermid());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
