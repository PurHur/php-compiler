<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArraySearchJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_search() AOT emits via ArraySearchLlvm (caller-frame), not NestedJIT VmArray stub (#12514, #27133).
 * VM execute() still uses ArraySearchJitHelper → VmArray::searchKey.
 */
final class ArraySearchRuntimeShrinkTest extends TestCase
{
    public function testArraySearchRuntimeUsesInlineLlvmNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArraySearchRuntime.php');
        $this->assertStringContainsString('ArraySearchLlvm::searchKey', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('__array_search__key', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arraySearch', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_search.php');
        $this->assertStringContainsString('ArraySearchRuntime::search', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arraySearch', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArraySearchLlvm.php');
        $this->assertStringContainsString('ArraySearchJitHelper', $llvm);
        $this->assertStringContainsString('#27133', $llvm);
        $this->assertStringContainsString('VmValueCompare::identicalValueToValue', $llvm);
        $this->assertStringContainsString('__value__writeLong', $llvm);
        $this->assertStringContainsString('__value__writeBool', $llvm);
    }

    public function testArraySearchJitHelperLooseMatch(): void
    {
        $haystack = new HashTable();
        $v = new Variable();
        $v->string('1');
        $haystack->addIndex(0, $v);

        $needle = new Variable();
        $needle->int(1);

        $result = ArraySearchJitHelper::searchKey($needle, $haystack, false);
        $this->assertSame(0, $result->toInt());
    }

    public function testArraySearchJitHelperStrictStringMatch(): void
    {
        $haystack = new HashTable();
        $v = new Variable();
        $v->string('foo');
        $haystack->add('bar', $v);

        $needle = new Variable();
        $needle->string('foo');

        $result = ArraySearchJitHelper::searchKey($needle, $haystack, true);
        $this->assertSame('bar', $result->toString());
    }

    public function testArraySearchJitHelperNotFound(): void
    {
        $haystack = new HashTable();
        $v = new Variable();
        $v->string('foo');
        $haystack->addIndex(0, $v);

        $needle = new Variable();
        $needle->string('missing');

        $result = ArraySearchJitHelper::searchKey($needle, $haystack, true);
        $this->assertFalse($result->toBool());
    }
}
