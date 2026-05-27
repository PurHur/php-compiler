<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VariableTypeMapNativeTest extends TestCase
{
    public function testIsNativeLoweringNameMatchesVmTypeMapHelpers(): void
    {
        $this->assertTrue(
            PHPCompiler\JIT\VariableTypeMapNative::isNativeLoweringName(
                'phpcompiler\\jit\\variable::fromvmvariable'
            )
        );
        $this->assertTrue(
            PHPCompiler\JIT\VariableTypeMapNative::isNativeLoweringName(
                'phpcompiler\\jit\\variable::jittypebytefromvmtype'
            )
        );
        $this->assertFalse(
            PHPCompiler\JIT\VariableTypeMapNative::isNativeLoweringName(
                'phpcompiler\\jit\\variable::getstringtype'
            )
        );
    }
}
