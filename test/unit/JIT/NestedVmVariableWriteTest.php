<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPUnit\Framework\TestCase;

/** Nested JIT Variable write helpers for php-in-PHP ext helpers (#13245). */
final class NestedVmVariableWriteTest extends TestCase
{
    public function testNestedVariableWriteMethodsRegistered(): void
    {
        foreach (['null', 'int', 'bool', 'string', 'float', 'array'] as $method) {
            $this->assertTrue(
                NestedVmVariableMethodLlvm::isNestedVariableMethod($method),
                'missing nested Variable::'.$method.'() handler'
            );
        }
    }
}
