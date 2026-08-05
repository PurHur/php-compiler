<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\KeySortJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * ksort()/krsort() — VM via KeySortJitHelper; thin AOT via Type\HashTable LLVM (#27227).
 * ArrayBuiltinHelper must not regain the deleted ksort/krsort monolith (#18381).
 */
final class KeySortRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 4520;

    public function testKeySortRuntimeUsesHashTableLlvmNotArrayBuiltinHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/KeySortRuntime.php');
        $this->assertStringContainsString('__hashtable__sortStringKeys', $runtime);
        $this->assertStringContainsString('__hashtable__sortStringKeysReverse', $runtime);
        $this->assertStringNotContainsString('KeySortJitHelper::', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::ksortByKey', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::krsortByKey', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $ksort = (string) file_get_contents(__DIR__.'/../../ext/standard/ksort_.php');
        $krsort = (string) file_get_contents(__DIR__.'/../../ext/standard/krsort_.php');
        $this->assertStringContainsString('KeySortRuntime::ksortByKey', $ksort);
        $this->assertStringContainsString('KeySortRuntime::krsortByKey', $krsort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::ksortByKey(', $ksort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::krsortByKey(', $krsort);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function ksortByKey', $arrayBuiltin);
        $this->assertStringNotContainsString('function krsortByKey', $arrayBuiltin);
        $this->assertStringNotContainsString('function krsortPackedListByKey', $arrayBuiltin);
        $this->assertStringNotContainsString('function sortStringKeys(', $arrayBuiltin);

        $hashtableType = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('__hashtable__sortStringKeys', $hashtableType);
        $this->assertStringContainsString('implementSortStringKeys', $hashtableType);
    }

    public function testArrayBuiltinHelperLineBudgetAfterKeysortLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead ksort/krsort LLVM deletion (#18381)'
        );
    }

    public function testKeySortJitHelperSortsStringKeysAscending(): void
    {
        $ht = self::assocTable(['b' => 2, 'a' => 1, 'c' => 3]);
        KeySortJitHelper::ksortByKey($ht);
        $this->assertSame(['a', 'b', 'c'], self::keysInOrder($ht));
    }

    public function testKeySortJitHelperSortsStringKeysDescending(): void
    {
        $ht = self::assocTable(['b' => 2, 'a' => 1, 'c' => 3]);
        KeySortJitHelper::krsortByKey($ht);
        $this->assertSame(['c', 'b', 'a'], self::keysInOrder($ht));
    }

    public function testKeySortJitHelperReversesPackedListKeys(): void
    {
        $ht = self::listTable(10, 20, 30);
        KeySortJitHelper::krsortByKey($ht);
        $this->assertSame([2 => 30, 1 => 20, 0 => 10], self::intKeyPairs($ht));
    }

    /** @param array<string, int> $pairs */
    private static function assocTable(array $pairs): HashTable
    {
        $ht = new HashTable();
        foreach ($pairs as $key => $value) {
            $var = new Variable();
            $var->int($value);
            $ht->add($key, $var);
        }

        return $ht;
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

    /** @return list<string> */
    private static function keysInOrder(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$key]) {
            $out[] = $key->resolveIndirect()->toString();
        }

        return $out;
    }

    /** @return array<int, int> */
    private static function intKeyPairs(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $out[$key->resolveIndirect()->toInt()] = $value->resolveIndirect()->toInt();
        }

        return $out;
    }
}
