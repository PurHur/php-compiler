<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FsDirJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsTempnamNative;
use PHPCompiler\ext\standard\VmFsTempnamPure;
use PHPCompiler\ext\standard\VmFsTouchNative;
use PHPCompiler\ext\standard\VmFsTouchPure;
use PHPCompiler\ext\standard\VmSysGetTempDirNative;
use PHPUnit\Framework\TestCase;

/** VmFsTouchPure / VmFsTempnamPure — touch/tempnam without libc FFI (#12145). */
final class VmFsTouchTempnamRuntimeShrinkTest extends TestCase
{
    public function testTouchNativeDelegatesToPure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsTouchNative.php');
        $this->assertStringContainsString('VmFsTouchPure::touch', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testTempnamNativeDelegatesToPure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsTempnamNative.php');
        $this->assertStringContainsString('VmFsTempnamPure::mkstemp', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testTouchPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsTouchPure.php');
        $this->assertStringContainsString('VmFsOpenNative::open', $source);
        $this->assertStringContainsString('VmFsTouchLibcThinAbi', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int utime', $source);
    }

    public function testTouchLibcThinAbiQuarantinesUtime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsTouchLibcThinAbi.php');
        $this->assertStringContainsString('FFI::cdef', $source);
        $this->assertStringContainsString('int utime', $source);
        $this->assertStringContainsString('#28995', $source);
    }

    public function testTempnamPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsTempnamPure.php');
        $this->assertStringContainsString("open(\$candidate, 'x')", $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->mkstemp', $source);
    }

    public function testTouchAndTempnamWithFfiDisabled(): void
    {
        $dir = sys_get_temp_dir();
        $path = $dir.'/phpc_touch_pure_'.bin2hex(random_bytes(4)).'.tmp';
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmFsTouchNative::available());
            $this->assertTrue(VmFsTouchNative::touch($path, 100, 100));
            $this->assertTrue(VmFsTouchNative::touch($path));
            $this->assertSame(VmFsTouchNative::touch($path, 100, 100), FsDirJitHelper::touch($path, 100, 100));

            $temp = VmFsTempnamNative::mkstemp($dir, 'phpc');
            $this->assertIsString($temp);
            $this->assertStringStartsWith($dir, $temp);
            @unlink($temp);
        } finally {
            @unlink($path);
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testTempnamJitHelperMatchesNative(): void
    {
        $dir = VmSysGetTempDirNative::resolve();
        $path = FsDirJitHelper::tempnam($dir, 'phpc');
        $this->assertIsString($path);
        @unlink($path);
    }
}
