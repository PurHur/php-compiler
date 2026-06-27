<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayPushJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_push() JIT routes through ArrayPushJitHelper PHP not ArrayBuiltinHelper LLVM (#12719). */
final class ArrayPushRuntimeShrinkTest extends TestCase
{
    public function testArrayPushRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayPushRuntime.php');
        $this->assertStringContainsString('ArrayPushJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::push', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_push.php');
        $this->assertStringContainsString('ArrayPushRuntime::push', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::push(', $builtin);
    }

    public function testArrayPushJitHelperAppendsValues(): void
    {
        $ht = self::listTable(1, 2);
        $tail = new Variable();
        $tail->int(3);
        $count = ArrayPushJitHelper::push($ht, $tail);
        $this->assertSame(3, $count);
        $this->assertSame(3, $ht->findIndex(2)?->toInt());
    }

    public function testArrayPushJitHelperZeroValuesReturnsCount(): void
    {
        $ht = self::listTable(4, 5);
        $this->assertSame(2, ArrayPushJitHelper::countElements($ht));
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
