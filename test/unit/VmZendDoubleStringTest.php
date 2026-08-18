<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmIni;
use PHPCompiler\ext\standard\VmZendDoubleString;
use PHPUnit\Framework\TestCase;

/** Issue #10143 / #21963: (string) float tokens match Zend (Zend/zend_operators.c). */
final class VmZendDoubleStringTest extends TestCase
{
    protected function tearDown(): void
    {
        VmIni::syncPrecision(14);
        parent::tearDown();
    }

    public function testNonFiniteDoubleStringTokens(): void
    {
        $this->assertSame('NAN', VmZendDoubleString::format(NAN));
        $this->assertSame('INF', VmZendDoubleString::format(INF));
        $this->assertSame('-INF', VmZendDoubleString::format(-INF));
        $this->assertSame('3.5', VmZendDoubleString::format(3.5));
    }

    public function testPrecisionIniHonored(): void
    {
        VmIni::syncPrecision(10);
        $this->assertSame('0.3333333333', VmZendDoubleString::format(1 / 3));
        VmIni::syncPrecision(-1);
        $this->assertSame('0.3333333333333333', VmZendDoubleString::format(1 / 3));
        VmIni::syncPrecision(14);
        $this->assertSame('0.33333333333333', VmZendDoubleString::format(1 / 3));
    }

    /** php-src zend_gcvt E-form keeps a fractional digit (#23545). */
    public function testScientificMantissaKeepsFractionalDigit(): void
    {
        VmIni::syncPrecision(14);
        $this->assertSame('1.0E+20', VmZendDoubleString::format(1e20));
        $this->assertSame('-1.0E+20', VmZendDoubleString::format(-1e20));
        $this->assertSame('1.0E-5', VmZendDoubleString::format(1e-5));
        $this->assertSame('1.5E+20', VmZendDoubleString::format(1.5e20));
    }

    /** libc snprintf %g → zend_gcvt (#32316). */
    public function testZendifySnprintfGMatchesZendGcvt(): void
    {
        $this->assertSame('1.0E+100', VmZendDoubleString::zendifySnprintfG('1e+100'));
        $this->assertSame('1.0E-5', VmZendDoubleString::zendifySnprintfG('1e-05'));
        $this->assertSame('1.5E+20', VmZendDoubleString::zendifySnprintfG('1.5e+20'));
        $this->assertSame('-1.0E+20', VmZendDoubleString::zendifySnprintfG('-1e+20'));
        $this->assertSame('3.5', VmZendDoubleString::zendifySnprintfG('3.5'));
        $this->assertSame('INF', VmZendDoubleString::zendifySnprintfG('INF'));
        $this->assertSame('1.0E+0', VmZendDoubleString::zendifySnprintfG('1e+00'));
    }
}
