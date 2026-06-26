<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFsGlob;
use PHPCompiler\ext\standard\VmFsGlobPure;
use PHPUnit\Framework\TestCase;

/** VmFsGlob — glob without libc FFI (#12208). */
final class VmFsGlobRuntimeShrinkTest extends TestCase
{
    public function testVmFsGlobDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsGlob.php');
        $this->assertStringContainsString('VmFsGlobPure::glob', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->glob', $source);
    }

    public function testVmFsGlobPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsGlobPure.php');
        $this->assertStringContainsString('VmFnmatch::match', $source);
        $this->assertStringContainsString('VmDirNative::listSorted', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testGlobWorksWithFfiDisabled(): void
    {
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmFsGlob::available());
            $matches = VmFsGlob::glob('composer.json', 0);
            $this->assertIsArray($matches);
            $this->assertContains('composer.json', $matches);
            $this->assertSame($matches, VmFsGlobPure::glob('composer.json', 0));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
