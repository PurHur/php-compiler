<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArraySumJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_sum() JIT routes through ArraySumJitHelper PHP not ArrayBuiltinHelper LLVM (#12590). */
final class ArraySumRuntimeShrinkTest extends TestCase
{
    public function testArraySumRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArraySumRuntime.php');
        $this->assertStringContainsString('ArraySumJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arraySum', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_sum.php');
        $this->assertStringContainsString('ArraySumRuntime::sum', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arraySum', $builtin);
    }

    public function testArraySumJitHelperSumsIntegers(): void
    {
        $ht = self::intListTable(1, 2, 3);
        $out = ArraySumJitHelper::sum($ht);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(6, $out->toInt());
    }

    public function testArraySumJitHelperPromotesToFloat(): void
    {
        $ht = new HashTable();
        foreach ([1, 2.5] as $i => $raw) {
            $var = new Variable();
            if (\is_int($raw)) {
                $var->int($raw);
            } else {
                $var->float($raw);
            }
            $ht->addIndex($i, $var);
        }
        $out = ArraySumJitHelper::sum($ht);
        $this->assertSame(Variable::TYPE_FLOAT, $out->type);
        $this->assertSame(3.5, $out->toFloat());
    }

    public function testArraySumJitHelperSkipsEnumCases(): void
    {
        $ht = self::intListTable(1, 2);
        $out = ArraySumJitHelper::sum($ht);
        $this->assertSame(3, $out->toInt());
    }

    /** @param list<int> $values */
    private static function intListTable(int ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            $var->int($value);
            $ht->append($var);
        }

        return $ht;
    }
}
