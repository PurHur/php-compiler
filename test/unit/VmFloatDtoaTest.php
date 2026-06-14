<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\VmFloatDtoa;

/** VmFloatDtoa var_dump / dtoa parity (#5412). */
final class VmFloatDtoaTest extends TestCase
{
    public function testLargeHexdecOverflowVarDumpFormat(): void
    {
        $h1 = hexdec('FFFFFFFFFFFFFFFF');
        $this->assertSame('1.8446744073709552E+19', VmFloatDtoa::formatVarDump($h1));
        $this->assertSame('9.223372036854776E+18', VmFloatDtoa::formatVarDump(hexdec('8000000000000000')));
        $this->assertSame(
            '3.6893488147419103E+19',
            VmFloatDtoa::formatVarDump(bindec(str_repeat('1', 65)))
        );
    }

    public function testNormalFloatUsesSerializeDtoaPath(): void
    {
        $this->assertSame('1.239', VmFloatDtoa::formatVarDump(1.239));
        $this->assertSame('42', VmFloatDtoa::formatVarDump(42.0));
    }
}
