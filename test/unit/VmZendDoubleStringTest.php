<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmZendDoubleString;
use PHPUnit\Framework\TestCase;

/** Issue #10143: (string) NAN/INF tokens match Zend (Zend/zend_operators.c). */
final class VmZendDoubleStringTest extends TestCase
{
    public function testNonFiniteDoubleStringTokens(): void
    {
        $this->assertSame('NAN', VmZendDoubleString::format(NAN));
        $this->assertSame('INF', VmZendDoubleString::format(INF));
        $this->assertSame('-INF', VmZendDoubleString::format(-INF));
        $this->assertSame('3.5', VmZendDoubleString::format(3.5));
    }
}
