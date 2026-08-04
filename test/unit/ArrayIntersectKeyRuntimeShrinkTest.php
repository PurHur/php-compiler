<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayIntersectKeyJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_intersect_key() JIT: call-site HashTableKeyFilterLlvm (#12551, #27521).
 */
final class ArrayIntersectKeyRuntimeShrinkTest extends TestCase
{
    public function testArrayIntersectKeyRuntimeUsesCallSiteLlvmNotNestedJitHelper(): void
    {
        // #27521: NestedJIT of ArrayIntersectKeyJitHelper returned empty under thin AOT.
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayIntersectKeyRuntime.php');
        $this->assertStringContainsString('HashTableKeyFilterLlvm', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::loadHashTable', $runtime);
        $this->assertStringContainsString('__array_intersect_key__copy', $runtime);
        $this->assertStringContainsString('__array_intersect_key__filter', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('ensureCompiled', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_intersect_key.php');
        $this->assertStringContainsString('ArrayIntersectKeyRuntime::intersectKey', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableKeyFilterLlvm.php');
        $this->assertStringContainsString('function intersectKey', $llvm);
        $this->assertStringContainsString('function diffKey', $llvm);
        $this->assertStringContainsString('filterByKeyPresence', $llvm);
        $this->assertStringContainsString('exportPairsForSlice', $llvm);
    }

    public function testArrayIntersectKeyJitHelperSingleCopy(): void
    {
        $base = new HashTable();
        $a = new Variable();
        $a->string('1');
        $base->add('a', $a);
        $b = new Variable();
        $b->string('2');
        $base->add('b', $b);

        $copy = ArrayIntersectKeyJitHelper::intersectKeySingleCopy($base);
        $this->assertNotSame($base, $copy);
        $this->assertSame(2, $copy->getNumElements());
    }

    public function testArrayIntersectKeyJitHelperKeepsSharedKeys(): void
    {
        $first = new HashTable();
        $a = new Variable();
        $a->string('1');
        $first->add('a', $a);
        $b = new Variable();
        $b->string('2');
        $first->add('b', $b);
        $c = new Variable();
        $c->string('3');
        $first->add('c', $c);

        $other = new HashTable();
        $d = new Variable();
        $d->string('9');
        $other->add('b', $d);
        $e = new Variable();
        $e->string('4');
        $other->add('d', $e);

        $result = ArrayIntersectKeyJitHelper::intersectKeyTwo($first, $other);
        $this->assertSame('2', $result->find('b')?->toString());
        $this->assertNull($result->find('a'));
        $this->assertNull($result->find('c'));
    }
}
