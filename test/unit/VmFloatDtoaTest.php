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

    /** zend_gcvt scientific form keeps ".0" (#23545). */
    public function testLargeScientificKeepsFractionalDigit(): void
    {
        $this->assertSame('1.0E+20', VmFloatDtoa::formatVarDump(1e20));
        $this->assertSame('1.0E+20', VmFloatDtoa::formatH(1e20));
    }

    /** php-src main/snprintf.c php_fcvt parity (#10796, #10415). */
    public function testSprintfFixedRoundingMatchesZend(): void
    {
        $this->assertSame('1.00', VmFloatDtoa::formatSprintfF(1.005, 2));
        $this->assertSame('2.67', VmFloatDtoa::formatSprintfF(2.675, 2));
        $this->assertSame('0', VmFloatDtoa::formatSprintfF(0.5, 0));
        $this->assertSame('2', VmFloatDtoa::formatSprintfF(1.5, 0));
        $this->assertSame('0.00', VmFloatDtoa::formatSprintfF(0.0, 2));
        // #10415 — %.17F must not cap at 15 fractional digits (DBL_DECIMAL_DIG parity).
        $this->assertSame('83.33333333333332860', VmFloatDtoa::formatSprintfF(5 * 200.0 / 12, 17));
    }

    /** php-src zend_gcvt / formatted_print.c %g significant digits (#24016). */
    public function testSprintfGeneralSignificantDigitsMatchZend(): void
    {
        $this->assertSame('1.2e+3', VmFloatDtoa::formatSprintfG(1234.0, 2));
        $this->assertSame('1.23e+3', VmFloatDtoa::formatSprintfG(1234.0, 3));
        $this->assertSame('1.0e+3', VmFloatDtoa::formatSprintfG(1234.0, 1));
        $this->assertSame('12', VmFloatDtoa::formatSprintfG(12.34, 2));
        $this->assertSame('0.012', VmFloatDtoa::formatSprintfG(0.01234, 2));
        $this->assertSame('1.0e+3', VmFloatDtoa::formatSprintfG(1234.0, 0));
        $this->assertSame('1.2E+3', VmFloatDtoa::formatSprintfG(1234.0, 2, true));
        $this->assertSame('10', VmFloatDtoa::formatSprintfG(9.99, 2));
        $this->assertSame('0.0001', VmFloatDtoa::formatSprintfG(9.99e-5, 2));
    }
}
