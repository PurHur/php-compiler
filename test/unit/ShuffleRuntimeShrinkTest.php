<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ShuffleJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** shuffle() JIT routes through ShuffleJitHelper PHP not __hashtable__shufflePacked LLVM (#12762). */
final class ShuffleRuntimeShrinkTest extends TestCase
{
    public function testShuffleRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ShuffleRuntime.php');
        $this->assertStringContainsString('ShuffleJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::shufflePacked', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/shuffle_.php');
        $this->assertStringContainsString('ShuffleRuntime::shufflePacked', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::shufflePacked(', $builtin);
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
