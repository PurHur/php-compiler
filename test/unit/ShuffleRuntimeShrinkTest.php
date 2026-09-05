<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ShuffleJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * shuffle() AOT uses call-site Fisher–Yates (NestedJIT ShuffleJitHelper is a no-op under
 * thin standalone AOT — #36397 slice 12). VM still routes through ShuffleJitHelper PHP.
 */
final class ShuffleRuntimeShrinkTest extends TestCase
{
    public function testShuffleRuntimeUsesCallSiteLlvmNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ShuffleRuntime.php');
        $this->assertStringContainsString('separateContainerForWrite', $runtime);
        $this->assertStringContainsString('emitFisherYatesPacked', $runtime);
        $this->assertStringContainsString('emitAssertExclusiveCall', $runtime);
        $this->assertStringContainsString('__compiler_random_bytes', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('__shuffle__packed', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/shuffle_.php');
        $this->assertStringContainsString('ShuffleRuntime::shufflePacked', $builtin);
    }

    public function testShuffleJitHelperPreservesElements(): void
    {
        $ht = self::listTable(1, 2, 3, 4, 5);
        ShuffleJitHelper::shufflePacked($ht);
        $values = self::valuesInOrder($ht);
        sort($values);
        $this->assertSame([1, 2, 3, 4, 5], $values);
        $this->assertCount(5, $values);
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

    /** @return list<int> */
    private static function valuesInOrder(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterate(true) as $value) {
            $out[] = $value->resolveIndirect()->toInt();
        }

        return $out;
    }
}
