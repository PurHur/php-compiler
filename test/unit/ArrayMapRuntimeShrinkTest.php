<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayMapJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_map() JIT routes null/string-builtin/closure through ArrayMapJitHelper PHP not ArrayBuiltinHelper LLVM (#10183, #14977, #18328). */
final class ArrayMapRuntimeShrinkTest extends TestCase
{
    public function testArrayMapRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayMapRuntime.php');
        $this->assertStringContainsString('ArrayMapJitHelper', $runtime);
        $this->assertStringContainsString('mapWithClosure', $runtime);
        $this->assertStringContainsString('mapWithClosureMultiple', $runtime);
        $this->assertStringNotContainsString('buildMapArrayWithClosure', $runtime);
        $this->assertStringNotContainsString('buildMapClosureZipFromMultiple', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildMapArray(', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayMapJitHelper.php');
        $this->assertStringContainsString('mapWithClosure', $helper);
        $this->assertStringContainsString('mapWithClosureMultiple', $helper);
        $this->assertStringContainsString('VmClosureCall', $helper);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_map.php');
        $this->assertStringContainsString('ArrayMapRuntime::mapSingle', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildMapArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringContainsString('ArrayMapRuntime::mapMultipleWithClosure', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildMapArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildMapFromHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildMapNullFromHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('buildMapClosureZipFromMultiple', $arrayBuiltin);
        $this->assertStringNotContainsString('buildMapFromHashTableWithClosure', $arrayBuiltin);
        $this->assertStringNotContainsString('buildMapFromNativeArrayWithClosure', $arrayBuiltin);
        $this->assertStringNotContainsString('closureMapReturnTypeTag', $arrayBuiltin);
    }

    public function testArrayMapJitHelperNullIdentityPreservesKeys(): void
    {
        $src = self::listTable(1, 2, 3);
        $identity = ArrayMapJitHelper::mapNullIdentity($src);
        $keys = [];
        foreach ($identity->iterateKeyed(true) as [$key, $value]) {
            $keys[] = $key->resolveIndirect()->toInt();
            $this->assertSame(Variable::TYPE_INTEGER, $value->resolveIndirect()->type);
        }
        $this->assertSame([0, 1, 2], $keys);
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
}
