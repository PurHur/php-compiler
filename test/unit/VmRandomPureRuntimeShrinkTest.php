<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmRandomNative;
use PHPCompiler\ext\standard\VmRandomPure;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** VmRandomPure — random_bytes without libc getrandom FFI (#8921, #12181). */
final class VmRandomPureRuntimeShrinkTest extends TestCase
{
    public function testVmRandomNativeDelegatesToPureWhenFfiDisabled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmRandomNative.php');
        $this->assertStringContainsString('VmRandomPure::randomBytes', $source);
        $this->assertStringContainsString('VmRandomPure::available()', $source);
    }

    public function testVmRandomPureDoesNotUseGetrandomFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmRandomPure.php');
        $this->assertStringContainsString('/dev/urandom', $source);
        $this->assertStringContainsString('VmFsReadNative::readSlice', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->getrandom/', $source);
    }

    public function testRandomBytesNoFfiReturnsRequestedLength(): void
    {
        if (!VmRandomPure::available()) {
            $this->markTestSkipped('/dev/urandom unavailable');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmRandomNative::available());
            $bytes = VmString::randomBytes(16);
            $this->assertSame(16, \strlen($bytes));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testRandomBytesValueErrorWhenLengthZero(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('random_bytes(): Argument #1 ($length) must be greater than 0');
        VmRandomPure::randomBytes(0);
    }
}
