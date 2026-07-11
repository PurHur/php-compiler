<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmZlibCore;
use PHPUnit\Framework\TestCase;

/** @covers issue #6476 — VmZlibNative removed; VmZlibCore is SSOT (#8837) */
final class VmZlibNativeTest extends TestCase
{
    public function testGzRoundTripWithoutHostGz(): void
    {
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
}
