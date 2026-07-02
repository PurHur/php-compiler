<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmVarExportFloat;
use PHPUnit\Framework\TestCase;

/** Issue #4633: var_export NAN/INF tokens match Zend (ext/standard/var.c). */
final class VmVarExportFloatTest extends TestCase
{
    public function testNanInfTokensMatchZend(): void
    {
        $this->assertSame('NAN', VmVarExportFloat::format(fdiv(0.0, 0.0)));
        $this->assertSame('INF', VmVarExportFloat::format(fdiv(1.0, 0.0)));
        $this->assertSame('-INF', VmVarExportFloat::format(fdiv(-1.0, 0.0)));
        $this->assertSame('NAN', VmVarExportFloat::format(-NAN));
    }

    public function testFiniteFloatGetsDecimalSuffix(): void
    {
        $this->assertSame('42.0', VmVarExportFloat::format(42.0));
        $this->assertSame('150.0', VmVarExportFloat::format(150.0));
        $this->assertSame('100.0', VmVarExportFloat::format(100.0));
        $this->assertSame('1.0E-10', VmVarExportFloat::format(1.0E-10));
    }

    /** hexdec/bindec overflow float — var_export ULP matches Zend (#14927). */
    public function testLargeOverflowFloatMatchesZendVarExport(): void
    {
        $hex = hexdec('FFFFFFFFFFFFFFFF');
        $this->assertSame('1.8446744073709552E+19', VmVarExportFloat::format($hex));
        $this->assertSame(
            '3.6893488147419103E+19',
            VmVarExportFloat::format(bindec(str_repeat('1', 65)))
        );
    }
}
