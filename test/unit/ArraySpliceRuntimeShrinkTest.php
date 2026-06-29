<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArraySpliceJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_splice() JIT routes through ArraySpliceJitHelper PHP not ArrayBuiltinHelper LLVM (#13643). */
final class ArraySpliceRuntimeShrinkTest extends TestCase
{
    public function testArraySpliceRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArraySpliceRuntime.php');
        $this->assertStringContainsString('ArraySpliceJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildSpliceArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_splice.php');
        $this->assertStringContainsString('ArraySpliceRuntime::splice', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildSpliceArray', $builtin);
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
