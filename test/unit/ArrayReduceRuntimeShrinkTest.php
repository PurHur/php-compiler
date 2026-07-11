<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayReduceJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_reduce() JIT routes string-builtin/closure through ArrayReduceJitHelper PHP not ArrayBuiltinHelper LLVM (#12646, #14979). */
final class ArrayReduceRuntimeShrinkTest extends TestCase
{
    public function testArrayReduceRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayReduceRuntime.php');
        $this->assertStringContainsString('ArrayReduceJitHelper', $runtime);
        $this->assertStringContainsString('reduceWithClosure', $runtime);
        $this->assertStringNotContainsString('buildReduceArrayWithClosure', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayReduceJitHelper.php');
        $this->assertStringContainsString('reduceWithClosure', $helper);
        $this->assertStringContainsString('VmClosureCall', $helper);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_reduce.php');
        $this->assertStringContainsString('ArrayReduceRuntime::reduce', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildReduceArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringContainsString('ArrayReduceRuntime::reduce', $arrayBuiltin);
        $this->assertStringNotContainsString('buildReduceFromHashTableWithClosure', $arrayBuiltin);
    }

    public function testArrayReduceJitHelperHonorsInitialOnEmpty(): void
    {
        $ht = new HashTable();
        $initial = new Variable();
        $initial->int(10);
        $out = ArrayReduceJitHelper::reduceWithBuiltin($ht, 'intval', $initial);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(10, $out->toInt());
    }

    public function testArrayReduceJitHelperEmptyWithoutInitialReturnsNull(): void
    {
        $ht = new HashTable();
        $nullInitial = new Variable();
        $nullInitial->null();
        $out = ArrayReduceJitHelper::reduceWithBuiltin($ht, 'intval', $nullInitial);
        $this->assertSame(Variable::TYPE_NULL, $out->type);
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
