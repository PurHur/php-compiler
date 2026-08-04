<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayDiffKeyJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_diff_key() JIT: call-site HashTableKeyFilterLlvm (#12553, #27522).
 */
final class ArrayDiffKeyRuntimeShrinkTest extends TestCase
{
    public function testArrayDiffKeyRuntimeUsesCallSiteLlvmNotNestedJitHelper(): void
    {
        // #27522: NestedJIT of ArrayDiffKeyJitHelper returned empty under thin AOT.
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayDiffKeyRuntime.php');
        $this->assertStringContainsString('HashTableKeyFilterLlvm', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::loadHashTable', $runtime);
        $this->assertStringContainsString('__array_diff_key__copy', $runtime);
        $this->assertStringContainsString('__array_diff_key__filter', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('ensureCompiled', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayDiffKey', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_diff_key.php');
        $this->assertStringContainsString('ArrayDiffKeyRuntime::diffKey', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayDiffKey', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableKeyFilterLlvm.php');
        $this->assertStringContainsString('function diffKey', $llvm);
        $this->assertStringContainsString('function intersectKey', $llvm);
    }

    public function testArrayDiffKeyJitHelperRemovesSharedKeys(): void
    {
        $first = new HashTable();
        $a = new Variable();
        $a->string('x');
        $first->add('a', $a);
        $b = new Variable();
        $b->string('y');
        $first->add('b', $b);

        $other = new HashTable();
        $c = new Variable();
        $c->string('z');
        $other->add('a', $c);

        $result = ArrayDiffKeyJitHelper::diffKeyTwo($first, $other);
        $this->assertSame('y', $result->find('b')?->toString());
        $this->assertNull($result->find('a'));
    }

    public function testArrayDiffKeyJitHelperSingleCopy(): void
    {
        $base = new HashTable();
        $a = new Variable();
        $a->string('1');
        $base->add('a', $a);

        $copy = ArrayDiffKeyJitHelper::diffKeySingleCopy($base);
        $this->assertNotSame($base, $copy);
        $this->assertSame(1, $copy->getNumElements());
    }
}
