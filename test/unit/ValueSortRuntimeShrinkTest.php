<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ValueSortJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** asort()/arsort() JIT routes through ValueSortJitHelper PHP not LLVM (#12771, #13053). */
final class ValueSortRuntimeShrinkTest extends TestCase
{
    public function testValueSortRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueSortRuntime.php');
        $this->assertStringContainsString('ValueSortJitHelper', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::isNativeArray', $runtime);

        $asort = (string) file_get_contents(__DIR__.'/../../ext/standard/asort_.php');
        $arsort = (string) file_get_contents(__DIR__.'/../../ext/standard/arsort_.php');
        $this->assertStringContainsString('ValueSortRuntime::asortByValue', $asort);
        $this->assertStringContainsString('ValueSortRuntime::arsortByValue', $arsort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::asortByValue(', $asort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arsortByValue(', $arsort);
    }

    public function testValueSortJitHelperSortsByValueAscendingPreservingKeys(): void
    {
        $ht = self::assocTable(['x' => 3, 'y' => 1, 'z' => 2]);
        ValueSortJitHelper::asortByValue($ht);
        $this->assertSame(['y' => 1, 'z' => 2, 'x' => 3], self::pairsInOrder($ht));
    }

    public function testValueSortJitHelperSortsByValueDescendingPreservingKeys(): void
    {
        $ht = self::assocTable(['x' => 3, 'y' => 1, 'z' => 2]);
        ValueSortJitHelper::arsortByValue($ht);
        $this->assertSame(['x' => 3, 'z' => 2, 'y' => 1], self::pairsInOrder($ht));
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

    /** @return array<string, int> */
    private static function pairsInOrder(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $out[$key->resolveIndirect()->toString()] = $value->resolveIndirect()->toInt();
        }

        return $out;
    }
}
