<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CompilerOperandChainNativeTest extends TestCase
{
    public function testIsNativeLoweringNameMatchesOperandChainHelpers(): void
    {
        $this->assertTrue(
            PHPCompiler\JIT\CompilerOperandChainNative::isNativeLoweringName(
                'phpcompiler\\compiler::unwrapoperandchain'
            )
        );
        $this->assertTrue(
            PHPCompiler\JIT\CompilerOperandChainNative::isNativeLoweringName(
                'phpcompiler\\compiler::operandschainequal'
            )
        );
        $this->assertFalse(
            PHPCompiler\JIT\CompilerOperandChainNative::isNativeLoweringName(
                'phpcompiler\\compiler::compileblock'
            )
        );
    }
}
