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
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('add'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('append'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('updateindex'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('padcopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('exportkeyvaluepairs'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('valuescopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('keyscopy'));
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('keysmatchingcopy'));
        $this->assertFalse(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('iteratekeyed'));
    }
}
