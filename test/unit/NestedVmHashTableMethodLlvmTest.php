<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPUnit\Framework\TestCase;

/** Nested HashTable JIT method registry for php-in-PHP JitHelpers (#14601). */
final class NestedVmHashTableMethodLlvmTest extends TestCase
{
    public function testRegistersPadCopyAndGetNumElements(): void
    {
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('getnumelements'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('padcopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('exportkeyvaluepairs'));
        $this->assertFalse(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('iteratekeyed'));
    }
}
