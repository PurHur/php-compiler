<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayDiffKeyJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_diff_key() JIT routes through ArrayDiffKeyJitHelper PHP not ArrayBuiltinHelper LLVM (#12553). */
final class ArrayDiffKeyRuntimeShrinkTest extends TestCase
{
    public function testArrayDiffKeyRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayDiffKeyRuntime.php');
        $this->assertStringContainsString('ArrayDiffKeyJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayDiffKey', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_diff_key.php');
        $this->assertStringContainsString('ArrayDiffKeyRuntime::diffKey', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayDiffKey', $builtin);
    }

    public function testArrayDiffKeyJitHelperRemovesSharedKeys(): void
    {
        $first = new HashTable();
        $a = new Variable();
        $a->string('x');
        $first->add('k', $a);
        $b = new Variable();
        $b->string('keep');
        $first->add('z', $b);

        $other = new HashTable();
        $c = new Variable();
        $c->string('other');
        $other->add('k', $c);

        $result = ArrayDiffKeyJitHelper::diffKeyTwo($first, $other);
        $this->assertNull($result->find('k'));
        $this->assertSame('keep', $result->find('z')?->toString());
    }
}
