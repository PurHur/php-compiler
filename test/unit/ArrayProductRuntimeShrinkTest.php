<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayProductJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_product() JIT routes through ArrayProductJitHelper PHP not ArrayBuiltinHelper LLVM (#12591). */
final class ArrayProductRuntimeShrinkTest extends TestCase
{
    public function testArrayProductRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayProductRuntime.php');
        $this->assertStringContainsString('ArrayProductJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayProduct', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_product.php');
        $this->assertStringContainsString('ArrayProductRuntime::product', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayProduct', $builtin);
    }

    public function testArrayProductJitHelperMultipliesIntegers(): void
    {
        $ht = self::intListTable(2, 3, 4);
        $out = ArrayProductJitHelper::product($ht);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(24, $out->toInt());
    }

    public function testArrayProductJitHelperEmptyArrayReturnsOne(): void
    {
        $ht = new HashTable();
        $out = ArrayProductJitHelper::product($ht);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(1, $out->toInt());
    }

    public function testArrayProductJitHelperPromotesToFloat(): void
    {
        $ht = new HashTable();
        foreach ([2, 2.5] as $i => $raw) {
            $var = new Variable();
            if (\is_int($raw)) {
                $var->int($raw);
            } else {
                $var->float($raw);
            }
            $ht->addIndex($i, $var);
        }
        $out = ArrayProductJitHelper::product($ht);
        $this->assertSame(Variable::TYPE_FLOAT, $out->type);
        $this->assertSame(5.0, $out->toFloat());
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
