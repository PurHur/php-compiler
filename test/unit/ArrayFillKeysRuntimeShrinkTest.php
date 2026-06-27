<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayFillKeysJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_fill_keys() JIT routes through ArrayFillKeysJitHelper PHP not ArrayBuiltinHelper LLVM (#12487). */
final class ArrayFillKeysRuntimeShrinkTest extends TestCase
{
    public function testArrayFillKeysRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFillKeysRuntime.php');
        $this->assertStringContainsString('ArrayFillKeysJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::fillKeys', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_fill_keys.php');
        $this->assertStringContainsString('ArrayFillKeysRuntime::fillKeys', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::fillKeys', $builtin);
    }

    public function testArrayFillKeysJitHelperMatchesVmFillKeysSemantics(): void
    {
        $keys = new HashTable();
        $a = new Variable();
        $a->string('foo');
        $keys->addIndex(0, $a);
        $b = new Variable();
        $b->string('bar');
        $keys->addIndex(1, $b);
        $fill = new Variable();
        $fill->string('baz');

        $out = ArrayFillKeysJitHelper::fillKeysCopy($keys, $fill);
        $assoc = [];
        foreach ($out->iterateKeyed(true) as [$key, $val]) {
            $assoc[Variable::TYPE_STRING === $key->type ? $key->toString() : $key->toInt()] = $val->toString();
        }
        $this->assertSame(['foo' => 'baz', 'bar' => 'baz'], $assoc);
    }
}
