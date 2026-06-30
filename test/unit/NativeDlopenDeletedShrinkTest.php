<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** libdl FFI preload removed — compression JIT uses PHP helpers only (#13880, #13858). */
final class NativeDlopenDeletedShrinkTest extends TestCase
{
    public function testNativeDlopenSourceDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/NativeDlopen.php');
    }

    public function testCompressionJitHasNoLibdlPreload(): void
    {
        foreach (['StringZlib.php', 'StringZstd.php', 'ZlibRuntime.php', 'GzStreamRuntime.php'] as $file) {
            $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/'.$file);
            $this->assertStringNotContainsString('NativeDlopen', $source, $file);
            $this->assertStringNotContainsString('preloadLibz', $source, $file);
            $this->assertStringNotContainsString('preloadLibzstd', $source, $file);
            $this->assertStringNotContainsString('FFI::cdef', $source, $file);
        }
    }
}
