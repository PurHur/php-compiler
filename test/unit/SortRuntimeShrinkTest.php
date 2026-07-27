<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SortJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * sort()/rsort() SORT_REGULAR use LLVM `__hashtable__sortPacked*` (#24010);
 * locale/natural still bridge SortJitHelper (#12769, #13049, #17775).
 */
final class SortRuntimeShrinkTest extends TestCase
{
    public function testSortRuntimeUsesLlvmPackedForRegularAndHelperForLocale(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SortRuntime.php');
        $this->assertStringContainsString('__hashtable__sortPacked', $runtime);
        $this->assertStringContainsString('__hashtable__sortPackedReverse', $runtime);
        $this->assertStringContainsString('SortJitHelper', $runtime);
        $this->assertStringContainsString('sortPackedLocale', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPacked', $runtime);
        $this->assertStringContainsString('loadHashTable', $runtime);
        $this->assertStringContainsString('storeHashtableInArrayVariable', $runtime);

        $sort = (string) file_get_contents(__DIR__.'/../../ext/standard/sort_.php');
        $rsort = (string) file_get_contents(__DIR__.'/../../ext/standard/rsort_.php');
        $this->assertStringContainsString('SortRuntime::sortPacked', $sort);
        $this->assertStringContainsString('SortRuntime::sortPackedReverse', $rsort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPacked(', $sort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPackedReverse(', $rsort);
    }

    public function testSortRuntimeShrinkTestDocMentionsIssue17775(): void
    {
        $source = (string) file_get_contents(__FILE__);
        $this->assertStringContainsString('#17775', $source);
    }

    public function testHashtableTypeLlvmRegistersSortPackedSymbols(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('__hashtable__sortPacked', $source);
        $this->assertStringContainsString('__hashtable__sortPackedReverse', $source);
        $this->assertStringNotContainsString('__hashtable__shufflePacked', $source);
    }

    public function testSortJitHelperSortsIntegersAscending(): void
    {
        $ht = self::listTable(3, 1, 2);
        SortJitHelper::sortPacked($ht);
        $this->assertSame([1, 2, 3], self::valuesInOrder($ht));
    }

    public function testSortJitHelperSortsIntegersDescending(): void
    {
        $ht = self::listTable(3, 1, 2);
        SortJitHelper::sortPackedReverse($ht);
        $this->assertSame([3, 2, 1], self::valuesInOrder($ht));
    }

    public function testSortJitHelperSortsStringsAscending(): void
    {
        $ht = self::stringListTable('c', 'a', 'b');
        SortJitHelper::sortPacked($ht);
        $this->assertSame(['a', 'b', 'c'], self::stringValuesInOrder($ht));
    }

    /** @param list<int> $values */
    private static function listTable(int ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            $var->int($value);
            $ht->append($var);
        }

        return $ht;
    }

    /** @param list<string> $values */
    private static function stringListTable(string ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($value);
            $ht->append($var);
        }

        return $ht;
    }

    /** @return list<int> */
    private static function valuesInOrder(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterate(true) as $value) {
            $out[] = $value->resolveIndirect()->toInt();
        }

        return $out;
    }

    /** @return list<string> */
    private static function stringValuesInOrder(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterate(true) as $value) {
            $out[] = $value->resolveIndirect()->toString();
        }

        return $out;
    }
}
