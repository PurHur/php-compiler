<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayMergeJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_merge() call-site LLVM via HashTableMergeLlvm (#27546; was NestedJIT #22954).
 */
final class ArrayMergeRuntimeShrinkTest extends TestCase
{
    public function testArrayMergeRuntimeUsesCallSiteLlvmNotNestedJit(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayMergeRuntime.php');
        $this->assertStringContainsString('HashTableMergeLlvm::mergeSingle', $runtime);
        $this->assertStringContainsString('HashTableMergeLlvm::mergeTwo', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::merge', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_merge.php');
        $this->assertStringContainsString('ArrayMergeRuntime::merge', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::merge', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableMergeLlvm.php');
        $this->assertStringContainsString('appendPackedEntries', $llvm);
        $this->assertStringContainsString('HashTableHelper::setAtStringKey', $llvm);
        $this->assertStringNotContainsString('exportPairsForSlice', $llvm);
    }

    public function testArrayMergeJitHelperMatchesVmArraySemantics(): void
    {
        $first = self::listTable(1, 2);
        $second = self::listTable(3);
        $merged = ArrayMergeJitHelper::mergeTwo($first, $second);
        $keys = [];
        foreach ($merged->iterateKeyed(true) as [$key, $value]) {
            $keys[] = $key->resolveIndirect()->toInt();
            $this->assertSame(Variable::TYPE_INTEGER, $value->resolveIndirect()->type);
        }
        $this->assertSame([0, 1, 2], $keys);

        $assocA = self::mapTable(['a' => 1]);
        $assocB = self::mapTable(['a' => 2, 'b' => 3]);
        $assocMerged = ArrayMergeJitHelper::mergeTwo($assocA, $assocB);
        $this->assertSame(2, $assocMerged->find('a')?->resolveIndirect()->toInt());
        $this->assertSame(3, $assocMerged->find('b')?->resolveIndirect()->toInt());

        $single = ArrayMergeJitHelper::mergeSingleCopy($assocA);
        $this->assertSame(1, $single->find('a')?->resolveIndirect()->toInt());
    }

    /** @param list<int> $values */
    private static function listTable(int ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->append($var);
        }

        return $ht;
    }

    /** @param array<string, int> $pairs */
    private static function mapTable(array $pairs): HashTable
    {
        $ht = new HashTable();
        foreach ($pairs as $key => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->add($key, $var);
        }

        return $ht;
    }
}
