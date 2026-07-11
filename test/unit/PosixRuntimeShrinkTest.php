<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/** Issue #7177: VmPosix must not delegate to host \\posix_*(). */
final class PosixRuntimeShrinkTest extends TestCase
{
    public function testVmPosixDoesNotReferenceHostPosix(): void
    {
        $source = file_get_contents(__DIR__.'/../../ext/posix/VmPosix.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString("function_exists('posix_", $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\posix_[a-z_]+\(/', $source);
        $this->assertStringNotContainsString('captureHostPosixErrno', $source);
    }

    public function testPosixHandlersLiveUnderExtPosix(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/posix/posix_getpid.php');
        $this->assertFileExists(__DIR__.'/../../ext/posix/posix_strerror.php');
        $this->assertFileExists(__DIR__.'/../../ext/posix/Module.php');
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('posix_abi', $linker);
    }

    public function testVmPosixStrerrorUsesPureMap(): void
    {
        $msg = VmPosix::strerror(2);
        $this->assertSame('No such file or directory', $msg);
        $this->assertSame(
            VmPosix::strerror(13),
            'Permission denied'
        );
    }
}
