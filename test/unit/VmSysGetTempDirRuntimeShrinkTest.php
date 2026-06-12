<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmChdirNative;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmGetcwdNative;
use PHPCompiler\ext\standard\VmSysGetTempDirNative;
use PHPUnit\Framework\TestCase;

/** VM sys_get_temp_dir/chdir without host Zend delegation (#8180). */
final class VmSysGetTempDirRuntimeShrinkTest extends TestCase
{
    public function testSysGetTempDirBuiltinDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/sys_get_temp_dir.php');
        $this->assertStringContainsString('VmSysGetTempDirNative::resolve', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\sys_get_temp_dir\\s*\\(/', $source);
    }

    public function testVmFsTempDirDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmSysGetTempDirNative::resolve', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\sys_get_temp_dir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\chdir\\s*\\(/', $source);
        $this->assertStringContainsString('VmChdirNative::chdir', $source);
    }

    public function testVmFsTempnamFallbackDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsTempnam.php');
        $this->assertStringContainsString('VmSysGetTempDirNative::resolve', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\sys_get_temp_dir\\s*\\(/', $source);
    }

    public function testResolveMatchesHostTempDirWhenAvailable(): void
    {
        $expected = (string) \sys_get_temp_dir();
        if ('' === $expected) {
            $this->markTestSkipped('host sys_get_temp_dir unavailable');
        }

        $this->assertSame($expected, VmSysGetTempDirNative::resolve());
    }

    public function testVmFsChdirTracksGetcwdNative(): void
    {
        $start = VmGetcwdNative::resolve();
        if (false === $start) {
            $this->markTestSkipped('VmGetcwdNative unavailable in this environment');
        }

        $base = VmSysGetTempDirNative::resolve().'/phpc_chdir_native_'.(string) \getmypid();
        if (!\is_dir($base) && !@\mkdir($base, 0777, true)) {
            $this->markTestSkipped('cannot create temp dir');
        }

        $this->assertTrue(VmFs::chdir($base));
        try {
            $here = VmGetcwdNative::resolve();
            $this->assertIsString($here);
            $this->assertSame(\realpath($base), $here);
        } finally {
            VmChdirNative::chdir($start);
            @\rmdir($base);
        }
    }
}
