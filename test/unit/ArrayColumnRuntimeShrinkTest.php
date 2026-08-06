<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayColumnJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_column() JIT uses call-site LLVM (ArrayColumnLlvm), not NestedJIT helpers (#14256, #26955). */
final class ArrayColumnRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 9150;

    public function testArrayColumnRuntimeUsesCallSiteLlvmNotNestedJit(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayColumnRuntime.php');
        $this->assertStringContainsString('ArrayColumnLlvm', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildColumnArray', $runtime);
        $this->assertStringContainsString('columnWithRuntimeKey', $runtime);
        $this->assertStringContainsString('ABI_COLUMN_RUNTIME', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        // Thin AOT NestedJIT string keys are `__string__*` — i8* bridges fail module verify (#26955).
        $this->assertStringContainsString("getTypeFromString('__string__*')", $runtime);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayColumnLlvm.php');
        $this->assertStringContainsString('columnWithStringKey', $llvm);
        $this->assertStringContainsString('__hashtable__readStringKeyValue', $llvm);
        // #27131 — NestedJIT json_encode needs dense packed metadata after appends.
        $this->assertStringContainsString('syncPackedListMetadata', $llvm);
        $this->assertStringContainsString('numElements', $llvm);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_column.php');
        $this->assertStringContainsString('ArrayColumnRuntime::column', $builtin);
        $this->assertStringContainsString('ArrayColumnRuntime::columnWithRuntimeKey', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildColumnArray(', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildColumnArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildColumnFromHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildColumnWithIndexFromHashTable', $arrayBuiltin);
    }

    /** Call-site LLVM must not pull EnumCaseEntry::fetchProperty (#26955). */
    public function testArrayColumnLlvmAvoidsEnumCaseEntryFetchProperty(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayColumnLlvm.php');
        $code = preg_replace('!//.*$!m', '', $llvm) ?? $llvm;
        $code = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $this->assertDoesNotMatchRegularExpression('/fetchProperty\s*\(/', $code);
        $this->assertDoesNotMatchRegularExpression('/hasProperty\s*\(/', $code);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeColumnLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_column native LLVM deletion (#17973)'
        );
    }

    public function testArrayColumnJitHelperMatchesInlineHaystackRepro(): void
    {
        $ht = new HashTable();
        foreach ([['n' => 'a'], ['n' => 'b']] as $i => $row) {
            $rowHt = new HashTable();
            $cell = new Variable();
            $cell->string('a' === $row['n'] ? 'a' : 'b');
            $rowHt->update('n', $cell);
            $rowVar = new Variable();
            $rowVar->array($rowHt);
            $ht->addIndex($i, $rowVar);
        }
        $out = ArrayColumnJitHelper::columnWithKey($ht, 'n');
        $this->assertSame('a', $out->findIndex(0)?->resolveIndirect()->toString());
        $this->assertSame('b', $out->findIndex(1)?->resolveIndirect()->toString());
    }

    public function testArrayColumnJitHelperRuntimeKeyMatchesCompileTimePath(): void
    {
        $ht = new HashTable();
        foreach ([['e' => 1], ['e' => 2]] as $i => $row) {
            $rowHt = new HashTable();
            $cell = new Variable();
            $cell->int($row['e']);
            $rowHt->update('e', $cell);
            $rowVar = new Variable();
            $rowVar->array($rowHt);
            $ht->addIndex($i, $rowVar);
        }
        $keyVar = new Variable();
        $keyVar->string('e');
        $runtimeOut = ArrayColumnJitHelper::columnWithRuntimeKey($ht, $keyVar);
        $staticOut = ArrayColumnJitHelper::columnWithKey($ht, 'e');
        $this->assertSame(
            $staticOut->findIndex(0)?->resolveIndirect()->toInt(),
            $runtimeOut->findIndex(0)?->resolveIndirect()->toInt()
        );
        $this->assertSame(
            $staticOut->findIndex(1)?->resolveIndirect()->toInt(),
            $runtimeOut->findIndex(1)?->resolveIndirect()->toInt()
        );
    }

    public function testArrayColumnJitHelperEnumCasesNameValue(): void
    {
        $ht = new HashTable();
        $enumClass = new \PHPCompiler\VM\ClassEntry('BackedE');
        $enumClass->isEnum = true;
        $enumClass->backedType = 'string';
        foreach ([['One', '1'], ['Two', '2']] as $i => [$caseName, $backing]) {
            $object = new \PHPCompiler\VM\ObjectEntry($enumClass);
            $object->isEnumCase = true;
            $object->enumCaseName = $caseName;
            $backingVar = new Variable();
            $backingVar->string($backing);
            $object->enumCaseValue = $backingVar;
            $caseVar = new Variable();
            $caseVar->object($object);
            $ht->addIndex($i, $caseVar);
        }
        $names = ArrayColumnJitHelper::columnWithKey($ht, 'name');
        $this->assertSame('One', $names->findIndex(0)?->resolveIndirect()->toString());
        $this->assertSame('Two', $names->findIndex(1)?->resolveIndirect()->toString());
        $values = ArrayColumnJitHelper::columnWithKey($ht, 'value');
        $this->assertSame('1', $values->findIndex(0)?->resolveIndirect()->toString());
        $this->assertSame('2', $values->findIndex(1)?->resolveIndirect()->toString());
    }
}
