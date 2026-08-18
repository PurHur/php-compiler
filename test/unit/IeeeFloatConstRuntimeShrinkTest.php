<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * IEEE specials must not use PHP FFI LLVMConstReal(double) (#32317).
 */
final class IeeeFloatConstRuntimeShrinkTest extends TestCase
{
    public function testConstantFromFloatUsesConstRealOfStringForSpecials(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('LLVMConstRealOfString', $source);
        $this->assertStringContainsString('LLVMConstFNeg', $source);
        $this->assertStringContainsString('#32317', $source);
    }

    public function testHelperBoxedInfFoldUsesConstantFromFloat(): void
    {
        $pre = (string) file_get_contents(__DIR__.'/../../lib/JIT/Helper.pre');
        $this->assertStringContainsString('constantFromFloat(-INF', $pre);
        $this->assertStringContainsString('constantFromFloat(NAN', $pre);
        $this->assertStringNotContainsString("constReal(-INF)", $pre);
    }
}
