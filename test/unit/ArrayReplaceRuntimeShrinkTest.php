<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayReplaceJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_replace() NestedJIT via JitVmHelperLink::ensureCompiled (#23807 / peer #22954).
 */
final class ArrayReplaceRuntimeShrinkTest extends TestCase
{
    public function testArrayReplaceRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayReplaceRuntime.php');
        $this->assertStringContainsString('ArrayReplaceJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayReplace', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

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

    public function testNestedVmRegistersReplaceCopyHandler(): void
    {
        $nested = (string) file_get_contents(__DIR__.'/../../lib/JIT/NestedVmHashTableMethodLlvm.php');
        $this->assertStringContainsString("'replacecopy' => Call\\HashTableReplaceCopy::class", $nested);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Call/HashTableReplaceCopy.php');
    }
}
