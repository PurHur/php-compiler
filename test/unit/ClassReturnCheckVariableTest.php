<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\ClassReturnCheck;
use PHPUnit\Framework\TestCase;

/** NestedJIT `: Variable` uses __value__* ABI — ClassReturnCheck must not TypeError (#26797). */
final class ClassReturnCheckVariableTest extends TestCase
{
    public function testVmVariableClassNamesRecognized(): void
    {
        $this->assertTrue(ClassReturnCheck::isVmVariableClass('PHPCompiler\\VM\\Variable'));
        $this->assertTrue(ClassReturnCheck::isVmVariableClass('\\PHPCompiler\\VM\\Variable'));
        $this->assertTrue(ClassReturnCheck::isVmVariableClass('Variable'));
        $this->assertFalse(ClassReturnCheck::isVmVariableClass('stdClass'));
        $this->assertFalse(ClassReturnCheck::isVmVariableClass('PHPCompiler\\VM\\HashTable'));
    }

    public function testVmHashTableStillDistinctFromVariable(): void
    {
        $this->assertTrue(ClassReturnCheck::isVmHashTableClass('PHPCompiler\\VM\\HashTable'));
        $this->assertFalse(ClassReturnCheck::isVmHashTableClass('PHPCompiler\\VM\\Variable'));
        $this->assertFalse(ClassReturnCheck::isVmVariableClass('PHPCompiler\\VM\\HashTable'));
    }
}
