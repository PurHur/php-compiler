<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayShiftJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_shift() JIT emits HashTableShiftLlvm (not ArrayBuiltinHelper monolith);
 * VM keeps ArrayShiftJitHelper PHP (#12672, #24025).
 */
final class ArrayShiftRuntimeShrinkTest extends TestCase
{
    public function testArrayShiftRuntimeUsesShiftLlvmNotBuiltinMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayShiftRuntime.php');
        $this->assertStringContainsString('HashTableShiftLlvm', $runtime);
        $this->assertStringContainsString('ArrayShiftJitHelper', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::shiftFirst', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function shiftFirst', $helper);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_shift.php');
        $this->assertStringContainsString('ArrayShiftRuntime::shift', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::shiftFirst', $builtin);

        $this->assertFileExists(__DIR__.'/../../lib/JIT/HashTableShiftLlvm.php');
        $this->assertTrue(
            \PHPCompiler\JIT\NestedVmHashTableMethodLlvm::isNestedHashTableMethod('shiftfirst')
        );
    }

    public function testArrayShiftJitHelperShiftsFirstElement(): void
    {
        $ht = self::listTable(1, 2, 3);
        $out = ArrayShiftJitHelper::shift($ht);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(1, $out->toInt());
        $this->assertSame(2, $ht->getNumElements());
    }

    public function testArrayShiftJitHelperEmptyReturnsNull(): void
    {
        $ht = new HashTable();
        $out = ArrayShiftJitHelper::shift($ht);
        $this->assertSame(Variable::TYPE_NULL, $out->type);
    }

    /** @param list<int|string> $values */
    private static function listTable(int|string ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            if (\is_int($value)) {
                $var->int($value);
            } else {
                $var->string($value);
            }
            $ht->append($var);
        }

        return $ht;
    }
}
