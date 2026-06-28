<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayDiffAssocJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_diff_assoc() JIT routes through ArrayDiffAssocJitHelper PHP not ArrayBuiltinHelper LLVM (#12552). */
final class ArrayDiffAssocRuntimeShrinkTest extends TestCase
{
    public function testArrayDiffAssocRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayDiffAssocRuntime.php');
        $this->assertStringContainsString('ArrayDiffAssocJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayDiffAssoc', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_diff_assoc.php');
        $this->assertStringContainsString('ArrayDiffAssocRuntime::diffAssoc', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayDiffAssoc', $builtin);
    }

    public function testArrayDiffAssocJitHelperRemovesMatchingPairs(): void
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
        $c->string('x');
        $other->add('k', $c);

        $result = ArrayDiffAssocJitHelper::diffAssocTwo($first, $other);
        $this->assertNull($result->find('k'));
        $this->assertSame('keep', $result->find('z')?->toString());
    }

    public function testArrayDiffAssocJitHelperLooseIntBoolValueCompare(): void
    {
        $first = new HashTable();
        $a = new Variable();
        $a->int(1);
        $first->addIndex(0, $a);

        $other = new HashTable();
        $b = new Variable();
        $b->bool(true);
        $other->addIndex(0, $b);

        $result = ArrayDiffAssocJitHelper::diffAssocTwo($first, $other);
        $this->assertNull($result->findIndex(0));
    }
}
