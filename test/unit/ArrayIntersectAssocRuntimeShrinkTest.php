<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayIntersectAssocJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_intersect_assoc() JIT routes through ArrayIntersectAssocJitHelper PHP not ArrayBuiltinHelper LLVM (#12636). */
final class ArrayIntersectAssocRuntimeShrinkTest extends TestCase
{
    public function testArrayIntersectAssocRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayIntersectAssocRuntime.php');
        $this->assertStringContainsString('ArrayIntersectAssocJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayIntersectAssoc', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_intersect_assoc.php');
        $this->assertStringContainsString('ArrayIntersectAssocRuntime::intersectAssoc', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayIntersectAssoc', $builtin);
    }

    public function testArrayIntersectAssocJitHelperKeepsMatchingPairs(): void
    {
        $first = new HashTable();
        $a = new Variable();
        $a->string('x');
        $first->add('k', $a);
        $b = new Variable();
        $b->string('drop');
        $first->add('z', $b);

        $other = new HashTable();
        $c = new Variable();
        $c->string('x');
        $other->add('k', $c);

        $result = ArrayIntersectAssocJitHelper::intersectAssocTwo($first, $other);
        $this->assertSame('x', $result->find('k')?->toString());
        $this->assertNull($result->find('z'));
    }
}
