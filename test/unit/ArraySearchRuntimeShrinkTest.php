<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArraySearchJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_search() JIT routes through ArraySearchJitHelper PHP not ArrayBuiltinHelper LLVM (#12514). */
final class ArraySearchRuntimeShrinkTest extends TestCase
{
    public function testArraySearchRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArraySearchRuntime.php');
        $this->assertStringContainsString('ArraySearchJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arraySearch', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_search.php');
        $this->assertStringContainsString('ArraySearchRuntime::search', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arraySearch', $builtin);
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
