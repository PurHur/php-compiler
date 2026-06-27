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

    public function testZlibEncodeDecodeEncodings(): void
    {
        $raw = 'hello zlib';
        foreach ([\ZLIB_ENCODING_RAW, \ZLIB_ENCODING_DEFLATE, \ZLIB_ENCODING_GZIP] as $encoding) {
            $enc = VmZlibCore::zlib_encode($raw, $encoding);
            $this->assertIsString($enc);
            $this->assertSame($raw, VmZlibCore::zlib_decode($enc));
        }
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
}
