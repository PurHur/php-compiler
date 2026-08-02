<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPUnit\Framework\TestCase;

/** NestedJIT HashTable::spliceInPlace registration for array_splice AOT (#27075). */
final class HashTableSpliceInPlaceNestedJitTest extends TestCase
{
    public function testSpliceInPlaceIsNestedHashTableMethod(): void
    {
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('spliceinplace'));
    }

    public function testSpliceLlvmAndCallWired(): void
    {
        $root = dirname(__DIR__, 3);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/HashTableSpliceInPlace.php');
        $this->assertStringContainsString('HashTableSpliceLlvm', $call);
        $this->assertStringContainsString('must not call', $call);
        $this->assertStringNotContainsString('ArraySpliceRuntime::', $call);

        $llvm = (string) file_get_contents($root.'/lib/JIT/HashTableSpliceLlvm.php');
        $this->assertStringContainsString('clonePacked', $llvm);
        $this->assertStringContainsString('php_array_splice', $llvm);
        $this->assertStringContainsString('copyTempOnto', $llvm);
        $this->assertStringNotContainsString('ArraySpliceRuntime::', $llvm);
    }
}
