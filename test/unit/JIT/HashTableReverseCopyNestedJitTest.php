<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPUnit\Framework\TestCase;

/** NestedJIT HashTable::reverseCopy registration for array_reverse AOT (#27067). */
final class HashTableReverseCopyNestedJitTest extends TestCase
{
    public function testReverseCopyIsNestedHashTableMethod(): void
    {
        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('reversecopy'));
    }

    public function testReverseLlvmAndCallWired(): void
    {
        $root = dirname(__DIR__, 3);
        $call = (string) file_get_contents($root.'/lib/JIT/Call/HashTableReverseCopy.php');
        $this->assertStringContainsString('HashTableReverseLlvm', $call);
        $this->assertStringContainsString('must not call', $call);
        $this->assertStringNotContainsString('ArrayReverseRuntime::', $call);

        $llvm = (string) file_get_contents($root.'/lib/JIT/HashTableReverseLlvm.php');
        $this->assertStringContainsString('exportPairsForSlice', $llvm);
        $this->assertStringContainsString('php_array_reverse', $llvm);
    }
}
