<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmRandomNative;
use PHPCompiler\ext\standard\VmRandomPure;
use PHPUnit\Framework\TestCase;

/** VmRandomNative — random_bytes without libc getrandom FFI (#12181). */
final class VmRandomNativeRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testVmStringUsesNativeRandomAndWallClock(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmString.php');
        $this->assertStringContainsString('VmRandomNative::randomBytes', $source);
        $this->assertStringContainsString('VmDate::wallClock()', $source);
        $this->assertStringNotContainsString("\\fopen('/dev/urandom'", $source);
        $this->assertStringNotContainsString('\\gettimeofday()', $source);
    }

    public function testVmRandomNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmRandomNative.php');
        $this->assertStringContainsString('VmRandomPure::randomBytes', $source);
        $this->assertStringContainsString('VmRandomPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->getrandom/', $source);
    }

    public function testRandomBytesVmReturnsRequestedLength(): void
    {
        if (!VmRandomNative::available()) {
            $this->markTestSkipped('/dev/urandom unavailable');
        }
        $bytes = \PHPCompiler\ext\standard\VmString::randomBytes(16);
        $this->assertSame(16, \strlen($bytes));
    }

    public function testRandomBytesWorksWithFfiDisabled(): void
    {
        if (!VmRandomPure::available()) {
            $this->markTestSkipped('/dev/urandom unavailable');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmRandomNative::available());
            $bytes = VmRandomNative::randomBytes(16);
            $this->assertSame(16, \strlen($bytes));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testUniqidVmReturnsNonEmpty(): void
    {
        $id = \PHPCompiler\ext\standard\VmString::uniqid('pfx', true);
        $this->assertStringStartsWith('pfx', $id);
        $this->assertGreaterThan(3, \strlen($id));
    }
}
