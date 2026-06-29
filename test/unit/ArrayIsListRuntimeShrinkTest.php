<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayIsListJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_is_list() JIT routes through ArrayIsListJitHelper PHP not JitArrayIsList LLVM (#13645). */
final class ArrayIsListRuntimeShrinkTest extends TestCase
{
    public function testArrayIsListRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayIsListRuntime.php');
        $this->assertStringContainsString('ArrayIsListJitHelper', $runtime);
        $this->assertStringContainsString('JitArrayIsList::invoke', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_is_list.php');
        $this->assertStringContainsString('ArrayIsListRuntime::isList', $builtin);
        $this->assertStringNotContainsString('JitArrayIsList::invoke', $builtin);
    }

    public function testArrayIsListJitHelperMatchesVmIsListSemantics(): void
    {
        $this->assertTrue(ArrayIsListJitHelper::isList(new HashTable()));

        $list = new HashTable();
        foreach ([1, 2] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $list->addIndex($i, $var);
        }
        $this->assertTrue(ArrayIsListJitHelper::isList($list));

        $assoc = new HashTable();
        $var = new Variable();
        $var->string('a');
        $assoc->add('x', $var);
        $this->assertFalse(ArrayIsListJitHelper::isList($assoc));
    }
}
