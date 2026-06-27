<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\KeySortJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** ksort()/krsort() JIT routes through KeySortJitHelper PHP not __hashtable__sortStringKeys LLVM (#12770). */
final class KeySortRuntimeShrinkTest extends TestCase
{
    public function testKeySortRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/KeySortRuntime.php');
        $this->assertStringContainsString('KeySortJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::ksortByKey', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $ksort = (string) file_get_contents(__DIR__.'/../../ext/standard/ksort_.php');
        $krsort = (string) file_get_contents(__DIR__.'/../../ext/standard/krsort_.php');
        $this->assertStringContainsString('KeySortRuntime::ksortByKey', $ksort);
        $this->assertStringContainsString('KeySortRuntime::krsortByKey', $krsort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::ksortByKey(', $ksort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::krsortByKey(', $krsort);
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

    /** @return list<string> */
    private static function keysInOrder(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$key]) {
            $out[] = $key->resolveIndirect()->toString();
        }

        return $out;
    }
}
