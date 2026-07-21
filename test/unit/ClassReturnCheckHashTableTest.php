<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\ClassReturnCheck;
use PHPUnit\Framework\TestCase;

/** NestedJIT `: HashTable` uses __hashtable__* ABI — ClassReturnCheck must not TypeError (#21888, #20652). */
final class ClassReturnCheckHashTableTest extends TestCase
{
    public function testVmHashTableClassNamesRecognized(): void
    {
        $this->assertTrue(ClassReturnCheck::isVmHashTableClass('PHPCompiler\\VM\\HashTable'));
        $this->assertTrue(ClassReturnCheck::isVmHashTableClass('\\PHPCompiler\\VM\\HashTable'));
        $this->assertTrue(ClassReturnCheck::isVmHashTableClass('HashTable'));
        $this->assertFalse(ClassReturnCheck::isVmHashTableClass('stdClass'));
        $this->assertFalse(ClassReturnCheck::isVmHashTableClass('PHPCompiler\\VM\\Variable'));
    }
}
