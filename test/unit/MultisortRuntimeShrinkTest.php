<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MultisortJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_multisort() JIT/AOT uses LLVM `__multisort__packed` (#26908);
 * MultisortJitHelper remains the Zend-hosted SSOT for unit tests (#15667).
 */
final class MultisortRuntimeShrinkTest extends TestCase
{
    public function testMultisortRuntimeUsesLlvmPackedNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MultisortRuntime.php');
        $this->assertStringContainsString('__multisort__packed', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('MultisortJitHelper::multisortPacked', $runtime);

        $hashTableType = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('implementMultisortPacked', $hashTableType);
        $this->assertStringContainsString("'__multisort__packed'", $hashTableType);

        $multisort = (string) file_get_contents(__DIR__.'/../../ext/standard/array_multisort.php');
        $this->assertStringContainsString('MultisortRuntime::multisortPacked', $multisort);
        $this->assertStringContainsString('SortRuntime::sortPacked', $multisort);

        $arrayHelper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function multisortPacked', $arrayHelper);
        $this->assertStringNotContainsString('multisortCoupledBubble', $arrayHelper);
    }

    public function testMultisortJitHelperCouplesPackedIntegerArrays(): void
    {
        $primary = self::intList(3, 1, 2);
        $companion = self::stringList('c', 'a', 'b');
        $packed = self::packTables($primary, $companion);
        MultisortJitHelper::multisortPacked($packed, 0);
        $this->assertSame([1, 2, 3], self::intValues($primary));
        $this->assertSame(['a', 'b', 'c'], self::stringValues($companion));
    }

    public function testMultisortJitHelperSupportsDescendingPrimary(): void
    {
        $primary = self::intList(1, 3, 2);
        $companion = self::stringList('a', 'c', 'b');
        $packed = self::packTables($primary, $companion);
        MultisortJitHelper::multisortPacked($packed, 1);
        $this->assertSame([3, 2, 1], self::intValues($primary));
        $this->assertSame(['c', 'b', 'a'], self::stringValues($companion));
    }

    /** @param list<int> $values */
    private static function intList(int ...$values): HashTable
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
    private static function stringList(string ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            $var->string($value);
            $ht->append($var);
        }

        return $ht;
    }

    /**
     * @param list<HashTable> $tables
     */
    private static function packTables(HashTable ...$tables): HashTable
    {
        $packed = new HashTable();
        foreach ($tables as $table) {
            $ref = new Variable();
            $ref->array($table);
            $packed->append($ref);
        }

        return $packed;
    }

    /** @return list<int> */
    private static function intValues(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterate(true) as $value) {
            $out[] = $value->resolveIndirect()->toInt();
        }

        return $out;
    }

    /** @return list<string> */
    private static function stringValues(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterate(true) as $value) {
            $out[] = $value->resolveIndirect()->toString();
        }

        return $out;
    }
}
