<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayFlipJitHelper;
use PHPCompiler\ext\standard\ArrayReverseJitHelper;
use PHPCompiler\ext\standard\ArrayValuesJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_flip/values/reverse JIT routes through JitHelper PHP not ArrayBuiltinHelper LLVM (#12329). */
final class ArrayFlipValuesReverseRuntimeShrinkTest extends TestCase
{
    public function testArrayFlipRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFlipRuntime.php');
        $this->assertStringContainsString('ArrayFlipJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildFlipArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_flip.php');
        $this->assertStringContainsString('ArrayFlipRuntime::flip', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildFlipArray', $builtin);
    }

    public function testArrayValuesRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayValuesRuntime.php');
        $this->assertStringContainsString('ArrayValuesJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildValuesArray', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_values.php');
        $this->assertStringContainsString('ArrayValuesRuntime::values', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildValuesArray', $builtin);
    }

    public function testArrayReverseRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayReverseRuntime.php');
        $this->assertStringContainsString('ArrayReverseJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildReverseArray', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_reverse.php');
        $this->assertStringContainsString('ArrayReverseRuntime::reverse', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildReverseArray', $builtin);
    }

    public function testArrayFlipJitHelperMatchesVmArraySemantics(): void
    {
        $src = self::mapTable(['a' => 1, 'b' => 2]);
        $flipped = ArrayFlipJitHelper::flip($src);
        $this->assertSame('a', $flipped->findIndex(1)?->resolveIndirect()->toString());
        $this->assertSame('b', $flipped->findIndex(2)?->resolveIndirect()->toString());
    }

    public function testArrayValuesJitHelperMatchesHashTableValuesCopy(): void
    {
        $src = self::mapTable(['x' => 10, 'y' => 20]);
        $values = ArrayValuesJitHelper::valuesCopy($src);
        $keys = [];
        foreach ($values->iterateKeyed(true) as [$key, $value]) {
            $keys[] = $key->resolveIndirect()->toInt();
            $this->assertContains($value->resolveIndirect()->toInt(), [10, 20]);
        }
        $this->assertSame([0, 1], $keys);
    }

    public function testArrayReverseJitHelperMatchesHashTableReverseCopy(): void
    {
        $src = self::listTable(1, 2, 3);
        $reversed = ArrayReverseJitHelper::reverseCopy($src, false);
        $keys = [];
        foreach ($reversed->iterateKeyed(true) as [$key, $value]) {
            $keys[] = $key->resolveIndirect()->toInt();
            $this->assertSame(Variable::TYPE_INTEGER, $value->resolveIndirect()->type);
        }
        $this->assertSame([0, 1, 2], $keys);
        $this->assertSame(3, $reversed->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(1, $reversed->findIndex(2)?->resolveIndirect()->toInt());
    }

    /** @param list<int> $values */
    private static function listTable(int ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->append($var);
        }

        return $ht;
    }

    /** @param array<string, int> $pairs */
    private static function mapTable(array $pairs): HashTable
    {
        $ht = new HashTable();
        foreach ($pairs as $key => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->add($key, $var);
        }

        return $ht;
    }
}
