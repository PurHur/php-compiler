<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPUnit\Framework\TestCase;

/** Nested JIT Variable::toObject() registration (#17391). */
final class NestedVmVariableToObjectTest extends TestCase
{
    public function testToObjectHandlerIsRegistered(): void
    {
        $this->assertTrue(NestedVmVariableMethodLlvm::isNestedVariableMethod('toobject'));
        $this->assertTrue(NestedVmVariableMethodLlvm::isNestedVariableMethod('resolveindirect'));
        $this->assertFalse(NestedVmVariableMethodLlvm::isNestedVariableMethod('missing'));
    }

    /** Nested JIT Variable::duplicateFrom() for usort/uksort AOT (#24142). */
    public function testDuplicateFromHandlerIsRegistered(): void
    {
        $this->assertTrue(NestedVmVariableMethodLlvm::isNestedVariableMethod('duplicatefrom'));
        $this->assertTrue(NestedVmVariableMethodLlvm::isNestedVariableMethod('copyfrom'));
    }
}
