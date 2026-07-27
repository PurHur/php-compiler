<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayMapJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_map(): closures via ArrayMapJitHelper NestedJIT; null/string builtins via ArrayMapLlvm (#10183, #14977, #18328, #23974). */
final class ArrayMapRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 1920;

    public function testArrayMapRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayMapRuntime.php');
        $this->assertStringContainsString('ArrayMapJitHelper', $runtime);
        $this->assertStringContainsString('ArrayMapLlvm', $runtime);
        $this->assertStringContainsString('mapWithClosure', $runtime);
        $this->assertStringContainsString('mapWithClosureMultiple', $runtime);
        $this->assertStringContainsString('mapNullZipMultiple', $runtime);
        $this->assertStringNotContainsString('buildMapArrayWithClosure', $runtime);
        $this->assertStringNotContainsString('buildMapClosureZipFromMultiple', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildMapArray(', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayMapLlvm.php');
        $this->assertStringContainsString('mapNull', $llvm);
        $this->assertStringContainsString('mapBuiltin', $llvm);
        $this->assertStringContainsString('strtoupper', $llvm);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayMapJitHelper.php');
        $this->assertStringContainsString('mapWithClosure', $helper);
        $this->assertStringContainsString('mapWithClosureMultiple', $helper);
        $this->assertStringContainsString('mapNullZipMultiple', $helper);
        $this->assertStringContainsString('VmClosureCall', $helper);
        $this->assertStringContainsString('exportKeyValuePairs', $helper);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_map.php');
        $this->assertStringContainsString('ArrayMapRuntime::mapSingle', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildMapArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringContainsString('ArrayMapRuntime::mapMultipleWithClosure', $arrayBuiltin);
        $this->assertStringContainsString('ArrayMapRuntime::mapNullZipMultiple', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildMapArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildMapFromHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildMapNullFromHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('buildMapClosureZipFromMultiple', $arrayBuiltin);
        $this->assertStringNotContainsString('buildMapFromHashTableWithClosure', $arrayBuiltin);
        $this->assertStringNotContainsString('buildMapFromNativeArrayWithClosure', $arrayBuiltin);
        $this->assertStringNotContainsString('closureMapReturnTypeTag', $arrayBuiltin);
        $this->assertStringNotContainsString('buildMapNullZipFromMultiple', $arrayBuiltin);
        $this->assertStringNotContainsString('buildNullZipRowAtIndex', $arrayBuiltin);
        $this->assertStringNotContainsString('loadMapSourceHashTables', $arrayBuiltin);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNullZipLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_map null-zip LLVM deletion (#18364)'
        );
    }

    public function testArrayMapJitHelperNullZipMultipleMatchesVmSemantics(): void
    {
        $a = self::listTable(1, 2);
        $b = self::listTable(3, 4);
        $packed = new HashTable();
        $va = new Variable();
        $va->array($a);
        $vb = new Variable();
        $vb->array($b);
        $packed->addIndex(0, $va);
        $packed->addIndex(1, $vb);

        $zipped = ArrayMapJitHelper::mapNullZipMultiple($packed);
        $rows = [];
        foreach ($zipped->iterateKeyed(true) as [$key, $value]) {
            $rows[(int) $key->resolveIndirect()->toInt()] = $value->resolveIndirect()->toArray();
        }
        $this->assertSame(1, $rows[0]->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(3, $rows[0]->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(2, $rows[1]->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(4, $rows[1]->findIndex(1)?->resolveIndirect()->toInt());
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
