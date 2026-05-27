<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\OperandNameNative;
use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class OperandNameNativeTest extends TestCase
{
    public function testIsNativeLoweringName(): void
    {
        $this->assertTrue(
            OperandNameNative::isNativeLoweringName('phpcompiler\\jit\\operandname::resolve')
        );
        $this->assertFalse(
            OperandNameNative::isNativeLoweringName('phpcompiler\\jit\\issethelper::compile')
        );
    }
}
