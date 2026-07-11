<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\bz2\VmBz2Core;
use PHPCompiler\ext\bz2\VmBz2Native;
use PHPUnit\Framework\TestCase;

/** VmBz2Native — bzip2 round-trip without libbz2 FFI (#8868, #12193). */
final class VmBz2NativeTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testRoundTripWithoutHostBz2(): void
    {
        $plain = 'hello bz2 bootstrap';
        $compressed = VmBz2Native::compress($plain);
        $this->assertIsString($compressed);
        $this->assertGreaterThan(0, \strlen($compressed));
        $this->assertSame($plain, VmBz2Native::decompress($compressed));
    }

    public function testEmptyStringRoundTrip(): void
    {
        $compressed = VmBz2Native::compress('');
        $this->assertIsString($compressed);
        $this->assertSame(14, \strlen($compressed));
        $this->assertSame('', VmBz2Native::decompress($compressed));
        $this->assertSame('', VmBz2Native::decompress(''));
    }

    public function testAlwaysAvailableWithoutFfi(): void
    {
        $this->assertTrue(VmBz2Native::available());
        $this->assertTrue(VmBz2Core::available());
    }

    public function testInvalidBlockSizeReturnsFalse(): void
    {
        $this->assertFalse(VmBz2Core::compress('x', 0));
        $this->assertFalse(VmBz2Core::compress('x', 10));
    }

    public function testInvalidWorkFactorReturnsFalse(): void
    {
        $this->assertFalse(VmBz2Core::compress('x', 4, -1));
        $this->assertFalse(VmBz2Core::compress('x', 4, 251));
    }

    public function testInvalidSmallReturnsFalse(): void
    {
        $this->assertFalse(VmBz2Core::decompress("BZh1\x00", -1));
        $this->assertFalse(VmBz2Core::decompress("BZh1\x00", 2));
    }

    public function testDecompressReferenceVectorHello(): void
    {
        $hex = '425a68343141592653591931653d00000081000244a000219a68334d07338bb9229c28480c98b29e80';
        $data = \hex2bin($hex);
        $this->assertIsString($data);
        $this->assertSame('hello', VmBz2Core::decompress($data));
    }

    public function testRepeatedRunRoundTrip(): void
    {
        $plain = \str_repeat('abc', 100);
        $compressed = VmBz2Native::compress($plain, 4, 0);
        $this->assertIsString($compressed);
        $this->assertStringStartsWith('BZh', $compressed);
        $this->assertSame($plain, VmBz2Native::decompress($compressed));
    }

    public function testVmBz2NativeDelegatesToCoreWithoutFfi(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/bz2/VmBz2Native.php');
        $this->assertStringContainsString('VmBz2Core::compress', $source);
        $this->assertStringContainsString('VmBz2Core::decompress', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }
}
