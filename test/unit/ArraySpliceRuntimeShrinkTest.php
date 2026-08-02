<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArraySpliceJitHelper;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_splice() JIT routes through call-site HashTableSpliceLlvm (#13643, #14304, #17967, #27075). */
final class ArraySpliceRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 9690;

    public function testArraySpliceRuntimeUsesCallSiteLlvmNotNestedJitHelper(): void
    {
        // #27075: NestedJIT of ArraySpliceJitHelper fatals on HashTable::spliceInPlace; inline HashTableSpliceLlvm.
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArraySpliceRuntime.php');
        $this->assertStringContainsString('HashTableSpliceLlvm', $runtime);
        $this->assertStringContainsString('loadHashTable', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildSpliceArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('ensureCompiled', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_splice.php');
        $this->assertStringContainsString('ArraySpliceRuntime::splice', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildSpliceArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildSpliceArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildSpliceFromHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function clonePackedHashTable', $arrayBuiltin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableSpliceLlvm.php');
        $this->assertStringContainsString('function spliceInPlace', $llvm);
        $this->assertStringContainsString('php_array_splice', $llvm);
        $this->assertStringNotContainsString('ArraySpliceRuntime::', $llvm);

        $this->assertTrue(NestedVmHashTableMethodLlvm::isNestedHashTableMethod('spliceinplace'));
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeSpliceLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_splice native LLVM deletion (#17967)'
        );
    }

    public function testArraySpliceJitHelperMatchesVmSpliceInPlaceSemantics(): void
    {
        $src = new HashTable();
        foreach ([10, 20, 30, 40] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $src->addIndex($i, $var);
        }

        $repl = new HashTable();
        $nine = new Variable();
        $nine->int(9);
        $repl->addIndex(0, $nine);

        $removed = ArraySpliceJitHelper::spliceInPlace($src, 1, true, 2, true, $repl);
        $this->assertSame(20, $removed->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(30, $removed->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(10, $src->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(9, $src->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(40, $src->findIndex(2)?->resolveIndirect()->toInt());

        $assoc = new HashTable();
        foreach (['a' => 1, 'b' => 2, 'c' => 3] as $key => $raw) {
            $var = new Variable();
            $var->int($raw);
            $assoc->add((string) $key, $var);
        }
        $tailRemoved = ArraySpliceJitHelper::spliceInPlace($assoc, 1, false, 0, false, null);
        $this->assertSame(2, $tailRemoved->find('b')?->resolveIndirect()->toInt());
        $this->assertSame(3, $tailRemoved->find('c')?->resolveIndirect()->toInt());
        $this->assertSame(1, $assoc->find('a')?->resolveIndirect()->toInt());
        $this->assertNull($assoc->find('b'));
    }
}
