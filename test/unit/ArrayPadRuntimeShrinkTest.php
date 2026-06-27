<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayPadJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_pad() JIT routes through ArrayPadJitHelper PHP not ArrayBuiltinHelper LLVM (#12476). */
final class ArrayPadRuntimeShrinkTest extends TestCase
{
    public function testArrayPadRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayPadRuntime.php');
        $this->assertStringContainsString('ArrayPadJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::pad', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_pad.php');
        $this->assertStringContainsString('ArrayPadRuntime::pad', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::pad', $builtin);
    }

    public function testArrayPadJitHelperMatchesVmPadCopySemantics(): void
    {
        $ht = new HashTable();
        foreach ([1, 2] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->addIndex($i, $var);
        }

        $padValue = new Variable();
        $padValue->int(0);

        $right = ArrayPadJitHelper::padCopy($ht, 4, $padValue);
        $this->assertSame(4, $right->getNumElements());
        $this->assertSame(0, $right->findIndex(2)?->resolveIndirect()->toInt());
        $this->assertSame(0, $right->findIndex(3)?->resolveIndirect()->toInt());

        $left = ArrayPadJitHelper::padCopy($ht, -4, $padValue);
        $this->assertSame(4, $left->getNumElements());
        $this->assertSame(0, $left->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(0, $left->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(1, $left->findIndex(2)?->resolveIndirect()->toInt());
    }
}
