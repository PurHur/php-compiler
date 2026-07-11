<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamLibcHandleJitHelper;
use PHPCompiler\ext\standard\StreamLibcThinAbi;
use PHPUnit\Framework\TestCase;

/** StreamLibcHandle JIT helper routes FILE* lifecycle through StreamLibcThinAbi (#14457). */
final class VmStreamLibcHandleRuntimeShrinkTest extends TestCase
{
    public function testStreamLibcHandleJitHelperHasNoFfiCdef(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamLibcHandleJitHelper.php');
        $this->assertStringContainsString('StreamLibcThinAbi::', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('private static function ffi(', $source);
    }

    public function testStreamLibcThinAbiDocumentsFileLifecycle(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamLibcThinAbi.php');
        $this->assertStringContainsString('FFI::cdef', $source);
        $this->assertStringContainsString('int fclose(FILE *stream)', $source);
        $this->assertStringContainsString('int feof(FILE *stream)', $source);
        $this->assertStringContainsString('int fflush(FILE *stream)', $source);
        $this->assertStringContainsString('int pclose(FILE *stream)', $source);
    }

    public function testStreamLibcHandleRegisterRoundTrip(): void
    {
        StreamLibcHandleJitHelper::resetForTest();
        $this->assertSame(0, StreamLibcHandleJitHelper::resolvePtr(7));
        StreamLibcHandleJitHelper::registerFromPtr(7, 0x1234);
        $this->assertSame(0x1234, StreamLibcHandleJitHelper::resolvePtr(7));
        StreamLibcHandleJitHelper::registerFromPtr(7, 0);
        $this->assertSame(0, StreamLibcHandleJitHelper::resolvePtr(7));
    }

    public function testStreamLibcThinAbiUnavailableWhenFfiDisabled(): void
    {
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertFalse(StreamLibcThinAbi::available());
            $this->assertFalse(StreamLibcHandleJitHelper::fclose(1));
            $this->assertTrue(StreamLibcHandleJitHelper::feof(1));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
