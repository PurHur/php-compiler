<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayReplaceJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_replace() JIT routes through ArrayReplaceJitHelper PHP not ArrayBuiltinHelper LLVM (#12516). */
final class ArrayReplaceRuntimeShrinkTest extends TestCase
{
    public function testArrayReplaceRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayReplaceRuntime.php');
        $this->assertStringContainsString('ArrayReplaceJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayReplace', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_replace.php');
        $this->assertStringContainsString('ArrayReplaceRuntime::replace', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayReplace', $builtin);
    }

    public function testArrayReplaceJitHelperSingleCopy(): void
    {
        $base = new HashTable();
        $v = new Variable();
        $v->string('a');
        $base->addIndex(0, $v);

        $copy = ArrayReplaceJitHelper::replaceSingleCopy($base);
        $this->assertNotSame($base, $copy);
        $this->assertSame('a', $copy->findIndex(0)?->toString());
    }

    public function testArrayReplaceJitHelperOverlay(): void
    {
        $base = new HashTable();
        $a = new Variable();
        $a->string('old');
        $base->addIndex(0, $a);
        $b = new Variable();
        $b->string('keep');
        $base->addIndex(1, $b);

        $overlay = new HashTable();
        $n = new Variable();
        $n->string('new');
        $overlay->addIndex(0, $n);
        $extra = new Variable();
        $extra->string('added');
        $overlay->addIndex(2, $extra);

        $result = ArrayReplaceJitHelper::replaceTwo($base, $overlay);
        $this->assertSame('new', $result->findIndex(0)?->toString());
        $this->assertSame('keep', $result->findIndex(1)?->toString());
        $this->assertSame('added', $result->findIndex(2)?->toString());
    }
}
