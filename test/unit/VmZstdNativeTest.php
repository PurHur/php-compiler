<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\zstd\VmZstdCore;
use PHPCompiler\ext\zstd\VmZstdNative;
use PHPUnit\Framework\TestCase;

/** VmZstdCore — zstd round-trip without libzstd FFI (#8869). */
final class VmZstdNativeTest extends TestCase
{
    public function testRoundTripWithoutHostZstd(): void
    {
        $plain = 'hello zstd bootstrap';
        $compressed = VmZstdNative::compress($plain);
        $this->assertIsString($compressed);
        $this->assertGreaterThan(0, \strlen($compressed));
        $this->assertSame($plain, VmZstdNative::decompress($compressed));
    }

    public function testEmptyStringRoundTrip(): void
    {
        $compressed = VmZstdNative::compress('');
        $this->assertIsString($compressed);
        $this->assertSame('', VmZstdNative::decompress($compressed));
    }

    public function testAlwaysAvailableWithoutFfi(): void
    {
        $this->assertTrue(VmZstdNative::available());
        $this->assertTrue(VmZstdCore::available());
    }

    public function testInvalidLevelReturnsFalse(): void
    {
        $this->assertFalse(VmZstdCore::compress('x', 0));
        $this->assertFalse(VmZstdCore::compress('x', 23));
    }
}
