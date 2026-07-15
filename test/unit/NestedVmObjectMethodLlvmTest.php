<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\NestedVmObjectMethodLlvm;
use PHPUnit\Framework\TestCase;

/** Nested ObjectEntry JIT method registry (#19048). */
final class NestedVmObjectMethodLlvmTest extends TestCase
{
    public function testRegistersCompareSpaceship(): void
    {
        $this->assertTrue(NestedVmObjectMethodLlvm::isNestedObjectMethod('comparespaceship'));
        $this->assertFalse(NestedVmObjectMethodLlvm::isNestedObjectMethod('cloneShallow'));
    }
}
