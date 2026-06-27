<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayCombineJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_combine() JIT routes through ArrayCombineJitHelper PHP not ArrayBuiltinHelper LLVM (#12502). */
final class ArrayCombineRuntimeShrinkTest extends TestCase
{
    public function testArrayCombineRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayCombineRuntime.php');
        $this->assertStringContainsString('ArrayCombineJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::combine', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_combine.php');
        $this->assertStringContainsString('ArrayCombineRuntime::combine', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::combine', $builtin);
    }

    public function testArrayCombineJitHelperMatchesCombineSemantics(): void
    {
        $keys = new HashTable();
        $k0 = new Variable();
        $k0->string('a');
        $keys->addIndex(0, $k0);
        $k1 = new Variable();
        $k1->string('b');
        $keys->addIndex(1, $k1);

        $values = new HashTable();
        $v0 = new Variable();
        $v0->int(1);
        $values->addIndex(0, $v0);
        $v1 = new Variable();
        $v1->int(2);
        $values->addIndex(1, $v1);

        $out = ArrayCombineJitHelper::combineCopy($keys, $values);
        $this->assertSame(1, $out->find('a')?->resolveIndirect()->toInt());
        $this->assertSame(2, $out->find('b')?->resolveIndirect()->toInt());
    }

    public function testArrayCombineJitHelperDuplicateKeysKeepLast(): void
    {
        $keys = new HashTable();
        $k0 = new Variable();
        $k0->string('k');
        $keys->addIndex(0, $k0);
        $k1 = new Variable();
        $k1->string('k');
        $keys->addIndex(1, $k1);

        $values = new HashTable();
        $v0 = new Variable();
        $v0->string('first');
        $values->addIndex(0, $v0);
        $v1 = new Variable();
        $v1->string('last');
        $values->addIndex(1, $v1);

        $out = ArrayCombineJitHelper::combineCopy($keys, $values);
        $this->assertSame('last', $out->find('k')?->resolveIndirect()->toString());
    }
}
