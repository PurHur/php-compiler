<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ArrayCountJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** count() COUNT_NORMAL JIT routes through ArrayCountJitHelper PHP not ArrayBuiltinHelper LLVM (#13276). */
final class ArrayCountRuntimeShrinkTest extends TestCase
{
    public function testArrayCountRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayCountRuntime.php');
        $this->assertStringContainsString('ArrayCountJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::getNumElements', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_count.php');
        $this->assertStringContainsString('ArrayCountRuntime::numElements', $builtin);
    }

    public function testArrayCountJitHelperMatchesHashTableNumElements(): void
    {
        $ht = new HashTable();
        for ($i = 0; $i < 3; ++$i) {
            $v = new Variable(Variable::TYPE_INTEGER);
            $v->int($i);
            $ht->append($v);
        }
        $this->assertSame(3, ArrayCountJitHelper::numElements($ht));
    }
}
