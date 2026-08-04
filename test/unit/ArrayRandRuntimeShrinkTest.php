<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayRandJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_rand() JIT/AOT uses ArrayRandLlvm (not NestedJIT Variable return) (#16135, #27547).
 */
final class ArrayRandRuntimeShrinkTest extends TestCase
{
    public function testArrayRandRuntimeUsesCallSiteLlvmNotNestedJitVariableBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayRandRuntime.php');
        $this->assertStringContainsString('ArrayRandLlvm', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('__array_rand__pick', $runtime);
        $this->assertStringNotContainsString('__hashtable__arrayRandPacked', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayRandLlvm.php');
        $this->assertStringContainsString('__compiler_random_bytes', $llvm);
        $this->assertStringContainsString('StringRandomBytes', $llvm);
        $this->assertStringContainsString('__value__writeLong', $llvm);

        $hashTable = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringNotContainsString('__hashtable__arrayRandPacked', $hashTable);
        $this->assertStringNotContainsString('implementArrayRandPacked', $hashTable);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_rand.php');
        $this->assertStringContainsString('ArrayRandRuntime::call', $builtin);
        $this->assertStringNotContainsString('JitArrayRand', $builtin);
    }

    public function testArrayRandJitHelperSingleKeyInRange(): void
    {
        $ht = self::intListTable(10, 20, 30);
        $key = ArrayRandJitHelper::pick($ht, 1);
        $this->assertContains($key->resolveIndirect()->toInt(), [0, 1, 2]);
    }

    public function testArrayRandJitHelperMultipleKeys(): void
    {
        $ht = self::intListTable(0, 1, 2, 3);
        $keys = ArrayRandJitHelper::pick($ht, 2);
        $this->assertSame(Variable::TYPE_ARRAY, $keys->type);
        $picked = [];
        foreach ($keys->toArray()->iterate(true) as $value) {
            $picked[] = $value->resolveIndirect()->toInt();
        }
        sort($picked);
        $this->assertCount(2, $picked);
        foreach ($picked as $k) {
            $this->assertContains($k, [0, 1, 2, 3]);
        }
    }

    /** @param list<int> $values */
    private static function intListTable(int ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $i => $value) {
            $var = new Variable();
            $var->int($value);
            $ht->addIndex($i, $var);
        }

        return $ht;
    }
}
