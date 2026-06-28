<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ArrayCountRecursiveJitHelper;
use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** count(COUNT_RECURSIVE) JIT routes through ArrayCountRecursiveJitHelper PHP not JitArrayCountRecursive LLVM (#13274). */
final class ArrayCountRecursiveRuntimeShrinkTest extends TestCase
{
    public function testArrayCountRecursiveRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayCountRecursiveRuntime.php');
        $this->assertStringContainsString('ArrayCountRecursiveJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::countRecursive', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_count.php');
        $this->assertStringContainsString('ArrayCountRecursiveRuntime::countRecursive', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::countRecursive', $builtin);
    }

    public function testArrayCountRecursiveJitHelperMatchesVmArraySemantics(): void
    {
        $inner = new HashTable();
        $v2 = new Variable(Variable::TYPE_INTEGER);
        $v2->int(2);
        $inner->append($v2);
        $v3 = new Variable(Variable::TYPE_INTEGER);
        $v3->int(3);
        $inner->append($v3);

        $outer = new HashTable();
        $v1 = new Variable(Variable::TYPE_INTEGER);
        $v1->int(1);
        $outer->append($v1);
        $nested = new Variable();
        $nested->array($inner);
        $outer->append($nested);

        $this->assertSame(
            VmArray::countRecursive($outer),
            ArrayCountRecursiveJitHelper::countRecursive($outer)
        );
    }
}
