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
}
