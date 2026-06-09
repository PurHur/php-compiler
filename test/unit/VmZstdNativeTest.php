<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\zstd\VmZstdNative;
use PHPUnit\Framework\TestCase;

/** @covers issue #6387 */
final class VmZstdNativeTest extends TestCase
{
    public function testRoundTripWithoutHostZstd(): void
    {
        if (!VmZstdNative::available()) {
            $this->markTestSkipped('libzstd FFI unavailable in this environment');
        }

        $plain = 'hello zstd bootstrap';
        $compressed = VmZstdNative::compress($plain);
        $this->assertIsString($compressed);
        $this->assertGreaterThan(0, \strlen($compressed));
        $this->assertSame($plain, VmZstdNative::decompress($compressed));
    }

    public function testEmptyStringRoundTrip(): void
    {
        if (!VmZstdNative::available()) {
            $this->markTestSkipped('libzstd FFI unavailable in this environment');
        }

        $compressed = VmZstdNative::compress('');
        $this->assertIsString($compressed);
        $this->assertSame('', VmZstdNative::decompress($compressed));
    }
}
