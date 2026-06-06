<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmZlibNative;
use PHPUnit\Framework\TestCase;

/** @covers issue #6476 */
final class VmZlibNativeTest extends TestCase
{
    public function testGzRoundTripWithoutHostGz(): void
    {
        if (!VmZlibNative::available()) {
            $this->markTestSkipped('libz FFI unavailable in this environment');
        }

        $plain = 'hello';
        $compressed = VmZlibNative::gzcompress($plain);
        $this->assertIsString($compressed);
        $this->assertSame($plain, VmZlibNative::gzuncompress($compressed));

        $raw = VmZlibNative::gzdeflate($plain);
        $this->assertIsString($raw);
        $this->assertSame($plain, VmZlibNative::gzinflate($raw));

        $gzip = VmZlibNative::gzencode($plain);
        $this->assertIsString($gzip);
        $this->assertSame("\x1f\x8b", substr($gzip, 0, 2));
        $this->assertSame($plain, VmZlibNative::gzdecode($gzip));
    }
}
