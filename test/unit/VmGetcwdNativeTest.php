<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmGetcwdNative;
use PHPUnit\Framework\TestCase;

/** @covers issue #5044 */
final class VmGetcwdNativeTest extends TestCase
{
    public function testVmFsDoesNotCallHostGetcwd(): void
    {
        $src = (string) \file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\getcwd\\s*\\(/', $src);
    }

    public function testResolveReturnsCurrentWorkingDirectory(): void
    {
        $expected = (string) \getcwd();
        if ('' === $expected) {
            $this->markTestSkipped('host getcwd unavailable');
        }

        $cwd = VmGetcwdNative::resolve();
        $this->assertIsString($cwd);
        $this->assertSame($expected, $cwd);
    }

    public function testVmFsGetcwdMatchesNativeResolve(): void
    {
        $expected = VmGetcwdNative::resolve();
        if (false === $expected) {
            $this->markTestSkipped('VmGetcwdNative unavailable in this environment');
        }

        $this->assertSame($expected, VmFs::getcwd());
    }

    public function testResolveTracksChdir(): void
    {
        $start = VmGetcwdNative::resolve();
        if (false === $start) {
            $this->markTestSkipped('VmGetcwdNative unavailable in this environment');
        }

        $base = \sys_get_temp_dir().'/phpc_getcwd_native_'.(string) \getmypid();
        if (!\is_dir($base) && !@\mkdir($base, 0777, true)) {
            $this->markTestSkipped('cannot create temp dir');
        }

        $this->assertTrue(@\chdir($base));
        try {
            $here = VmGetcwdNative::resolve();
            $this->assertIsString($here);
            $this->assertSame(\realpath($base), $here);
        } finally {
            @\chdir($start);
            @\rmdir($base);
        }
    }
}
