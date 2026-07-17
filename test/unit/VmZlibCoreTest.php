<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmZlibCore;
use PHPUnit\Framework\TestCase;

/** @covers issue #8837 */
final class VmZlibCoreTest extends TestCase
{
    public function testGzRoundTripWithoutLibzFfi(): void
    {
        $this->assertTrue(VmZlibCore::available());

        $plain = 'hello';
        $compressed = VmZlibCore::gzcompress($plain);
        $this->assertIsString($compressed);
        $this->assertSame($plain, VmZlibCore::gzuncompress($compressed));

        $raw = VmZlibCore::gzdeflate($plain);
        $this->assertIsString($raw);
        $this->assertSame($plain, VmZlibCore::gzinflate($raw));

        $gzip = VmZlibCore::gzencode($plain);
        $this->assertIsString($gzip);
        $this->assertSame("\x1f\x8b", substr($gzip, 0, 2));
        $this->assertSame($plain, VmZlibCore::gzdecode($gzip));
    }

    /** Issue #19516 — gzip OS byte Unix 0x03; XFL matches zlib (level 1 → 0x04). */
    public function testGzencodeOsByteUnixAndXfl(): void
    {
        $gzip = VmZlibCore::gzencode('hello', 1);
        $this->assertIsString($gzip);
        $this->assertSame('03', bin2hex($gzip[9]));
        $this->assertSame('1f8b0800000000000403', bin2hex(substr($gzip, 0, 10)));
        $this->assertSame('hello', VmZlibCore::gzdecode($gzip));

        $gzip9 = VmZlibCore::gzencode('hello', 9);
        $this->assertIsString($gzip9);
        $this->assertSame('1f8b0800000000000203', bin2hex(substr($gzip9, 0, 10)));
    }

    public function testZlibEncodeDecodeEncodings(): void
    {
        $raw = 'hello zlib';
        foreach ([\ZLIB_ENCODING_RAW, \ZLIB_ENCODING_DEFLATE, \ZLIB_ENCODING_GZIP] as $encoding) {
            $enc = VmZlibCore::zlib_encode($raw, $encoding);
            $this->assertIsString($enc);
            $this->assertSame($raw, VmZlibCore::zlib_decode($enc));
        }
    }

    /** Issue #19907 — empty/null-coerced data must fail like Zend (not rawInflate empty string). */
    public function testZlibDecodeEmptyReturnsFalse(): void
    {
        $this->assertFalse(VmZlibCore::zlib_decode(''));
    }

    public function testIssueReproCompressesRepeatingPayload(): void
    {
        $data = str_repeat('abc', 100);
        $c = VmZlibCore::gzcompress($data, 6);
        $this->assertIsString($c);
        $this->assertLessThan(strlen($data), strlen($c));
        $this->assertSame($data, VmZlibCore::gzinflate(substr($c, 2, -4)));
    }

    /** Issue #12706 — raw deflate EOB must match libz (no sdefl zlib-partial-flush tail). */
    public function testRawDeflateHelloMatchesLibzHex(): void
    {
        if (!\function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension required for reference hex');
        }

        $plain = 'hello';
        $zendHex = bin2hex((string) zlib_encode($plain, ZLIB_ENCODING_RAW));
        $vmHex = bin2hex((string) VmZlibCore::gzdeflate($plain));
        $this->assertSame($zendHex, $vmHex);
        $this->assertSame($plain, VmZlibCore::gzinflate((string) VmZlibCore::gzdeflate($plain)));
    }

    /** Issue #14251 — homogeneous payload raw deflate bytes match libz (ext/zlib/zlib.c). */
    public function testRawDeflateRepeatingPayloadMatchesLibzHex(): void
    {
        if (!\function_exists('zlib_encode')) {
            $this->markTestSkipped('zlib extension required for reference hex');
        }

        $plain = str_repeat('a', 100);
        $zendHex = bin2hex((string) zlib_encode($plain, ZLIB_ENCODING_RAW));
        $vmHex = bin2hex((string) VmZlibCore::gzdeflate($plain));
        $this->assertSame('4b4ca43d0000', $zendHex);
        $this->assertSame($zendHex, $vmHex);
    }
}
