<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayShiftJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_shift() JIT routes through ArrayShiftJitHelper PHP not ArrayBuiltinHelper LLVM (#12672). */
final class ArrayShiftRuntimeShrinkTest extends TestCase
{
    public function testArrayShiftRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayShiftRuntime.php');
        $this->assertStringContainsString('ArrayShiftJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::shiftFirst', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_shift.php');
        $this->assertStringContainsString('ArrayShiftRuntime::shift', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::shiftFirst', $builtin);
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
