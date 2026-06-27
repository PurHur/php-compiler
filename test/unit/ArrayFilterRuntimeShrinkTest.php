<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayFilterJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_filter() JIT routes through ArrayFilterJitHelper PHP not ArrayBuiltinHelper LLVM (#12370). */
final class ArrayFilterRuntimeShrinkTest extends TestCase
{
    public function testArrayFilterRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFilterRuntime.php');
        $this->assertStringContainsString('ArrayFilterJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildFilterArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_filter.php');
        $this->assertStringContainsString('ArrayFilterRuntime::filterDefault', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildFilterArray', $builtin);
    }

    public function testArrayFilterJitHelperMatchesVmFilterDefaultSemantics(): void
    {
        $ht = new HashTable();
        foreach ([0, 'x', '', false, 1] as $i => $raw) {
            $var = new Variable();
            if (\is_int($raw)) {
                $var->int($raw);
            } elseif (\is_string($raw)) {
                $var->string($raw);
            } else {
                $var->bool($raw);
            }
            $ht->addIndex($i, $var);
        }
        $filtered = ArrayFilterJitHelper::filterDefault($ht);
        $this->assertSame('x', $filtered->findIndex(1)?->resolveIndirect()->toString());
        $this->assertSame(1, $filtered->findIndex(4)?->resolveIndirect()->toInt());
        $this->assertNull($filtered->findIndex(0));
        $this->assertNull($filtered->findIndex(2));
    }
}
