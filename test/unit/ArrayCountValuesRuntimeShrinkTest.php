<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayCountValuesJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_count_values() JIT routes through ArrayCountValuesJitHelper PHP not ArrayBuiltinHelper LLVM (#12331). */
final class ArrayCountValuesRuntimeShrinkTest extends TestCase
{
    public function testArrayCountValuesRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayCountValuesRuntime.php');
        $this->assertStringContainsString('ArrayCountValuesJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::arrayCountValues', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_count_values.php');
        $this->assertStringContainsString('ArrayCountValuesRuntime::countValues', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayCountValues', $builtin);
    }

    public function testArrayCountValuesJitHelperMatchesVmArraySemantics(): void
    {
        $ht = new HashTable();
        foreach (['a', 'b', 'a'] as $value) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($value);
            $ht->append($var);
        }
        $counts = ArrayCountValuesJitHelper::countValues($ht);
        $this->assertSame(2, $counts->find('a')?->resolveIndirect()->toInt());
        $this->assertSame(1, $counts->find('b')?->resolveIndirect()->toInt());
    }
}
