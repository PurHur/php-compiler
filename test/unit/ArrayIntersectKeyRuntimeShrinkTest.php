<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayIntersectKeyJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_intersect_key() JIT routes through ArrayIntersectKeyJitHelper PHP not ArrayBuiltinHelper LLVM (#12551). */
final class ArrayIntersectKeyRuntimeShrinkTest extends TestCase
{
    public function testArrayIntersectKeyRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayIntersectKeyRuntime.php');
        $this->assertStringContainsString('ArrayIntersectKeyJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayIntersectKey', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_intersect_key.php');
        $this->assertStringContainsString('ArrayIntersectKeyRuntime::intersectKey', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayIntersectKey', $builtin);
    }

    public function testArrayIntersectKeyJitHelperSingleCopy(): void
    {
        $base = new HashTable();
        $v = new Variable();
        $v->string('a');
        $base->add('x', $v);

        $copy = ArrayIntersectKeyJitHelper::intersectKeySingleCopy($base);
        $this->assertNotSame($base, $copy);
        $this->assertSame('a', $copy->find('x')?->toString());
    }

    public function testArrayIntersectKeyJitHelperKeyOnly(): void
    {
        $first = new HashTable();
        $a = new Variable();
        $a->string('keep');
        $first->add('k', $a);
        $b = new Variable();
        $b->string('drop');
        $first->add('z', $b);

        $other = new HashTable();
        $c = new Variable();
        $c->string('other-value');
        $other->add('k', $c);

        $result = ArrayIntersectKeyJitHelper::intersectKeyTwo($first, $other);
        $this->assertSame('keep', $result->find('k')?->toString());
        $this->assertNull($result->find('z'));
    }
}
