<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayPadJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_pad() JIT uses HashTablePadLlvm call-site (not NestedJIT HashTable return) (#12476, #26971). */
final class ArrayPadRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 8230;

    public function testArrayPadRuntimeUsesHashTablePadLlvmNotNestedJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayPadRuntime.php');
        $this->assertStringContainsString('HashTablePadLlvm', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('ensureBridge', $runtime);
        $this->assertDoesNotMatchRegularExpression('/padCopyLegacy|padCopyTyped/', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::pad', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $padLlvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTablePadLlvm.php');
        $this->assertStringContainsString('HashTableCowLlvm::duplicate', $padLlvm);
        $this->assertStringContainsString('ARRAY_PAD_MAX_PAD_SIZE', $padLlvm);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_pad.php');
        $this->assertStringContainsString('ArrayPadRuntime::pad', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::pad', $builtin);

        $padCopy = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/HashTablePadCopy.php');
        $this->assertStringContainsString('ArrayPadRuntime::pad', $padCopy);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::pad', $padCopy);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativePadLlvmDeletion(): void
    {
        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function pad(', $arrayBuiltin);
        $this->assertStringNotContainsString('function padWithType', $arrayBuiltin);
        $this->assertStringNotContainsString('function padHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function copyPackedListHashTable', $arrayBuiltin);

        $lines = substr_count($arrayBuiltin, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_pad native LLVM deletion (#18121)'
        );
    }

    public function testArrayPadJitHelperMatchesVmPadCopySemantics(): void
    {
        $ht = new HashTable();
        foreach ([1, 2] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->addIndex($i, $var);
        }

        $padValue = new Variable();
        $padValue->int(0);

        $right = ArrayPadJitHelper::padCopyLegacy($ht, 4, $padValue);
        $this->assertSame(4, $right->getNumElements());
        $this->assertSame(0, $right->findIndex(2)?->resolveIndirect()->toInt());
        $this->assertSame(0, $right->findIndex(3)?->resolveIndirect()->toInt());

        $left = ArrayPadJitHelper::padCopyLegacy($ht, -4, $padValue);
        $this->assertSame(4, $left->getNumElements());
        $this->assertSame(0, $left->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(0, $left->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(1, $left->findIndex(2)?->resolveIndirect()->toInt());
        $this->assertSame(2, $left->findIndex(3)?->resolveIndirect()->toInt());
    }

    public function testArrayPadLeftPadPreservesAssociativeStringKeys(): void
    {
        $ht = new HashTable();
        foreach (['a' => 1, 'b' => 2, 'c' => 3] as $key => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->add($key, $var);
        }

        $padValue = new Variable();
        $padValue->int(0);

        $left = ArrayPadJitHelper::padCopyLegacy($ht, -4, $padValue);
        $this->assertSame(4, $left->getNumElements());
        $this->assertSame(0, $left->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(1, $left->find('a')?->resolveIndirect()->toInt());
        $this->assertSame(2, $left->find('b')?->resolveIndirect()->toInt());
        $this->assertSame(3, $left->find('c')?->resolveIndirect()->toInt());
    }
}
