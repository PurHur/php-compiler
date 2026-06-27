<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayUnshiftJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_unshift() JIT routes through ArrayUnshiftJitHelper PHP not ArrayBuiltinHelper LLVM (#12717). */
final class ArrayUnshiftRuntimeShrinkTest extends TestCase
{
    public function testArrayUnshiftRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayUnshiftRuntime.php');
        $this->assertStringContainsString('ArrayUnshiftJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::unshift', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_unshift.php');
        $this->assertStringContainsString('ArrayUnshiftRuntime::unshift', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::unshift', $builtin);
    }

    public function testArrayUnshiftJitHelperPrependsValues(): void
    {
        $ht = self::listTable(2, 3);
        $prepend = new Variable();
        $prepend->int(0);
        $extra = new Variable();
        $extra->int(1);
        $count = ArrayUnshiftJitHelper::unshift($ht, $prepend, $extra);
        $this->assertSame(4, $count);
        $this->assertSame(0, $ht->findIndex(0)?->toInt());
        $this->assertSame(1, $ht->findIndex(1)?->toInt());
        $this->assertSame(2, $ht->findIndex(2)?->toInt());
        $this->assertSame(3, $ht->findIndex(3)?->toInt());
    }

    public function testArrayUnshiftJitHelperZeroValuesReturnsCount(): void
    {
        $ht = self::listTable(1, 2);
        $this->assertSame(2, ArrayUnshiftJitHelper::countElements($ht));
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
