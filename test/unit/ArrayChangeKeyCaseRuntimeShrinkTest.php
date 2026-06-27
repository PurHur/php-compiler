<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayChangeKeyCaseJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_change_key_case() JIT routes through ArrayChangeKeyCaseJitHelper PHP (#12371). */
final class ArrayChangeKeyCaseRuntimeShrinkTest extends TestCase
{
    public function testArrayChangeKeyCaseRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayChangeKeyCaseRuntime.php');
        $this->assertStringContainsString('ArrayChangeKeyCaseJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildChangeKeyCaseArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_change_key_case.php');
        $this->assertStringContainsString('ArrayChangeKeyCaseRuntime::changeKeyCase', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildChangeKeyCaseArray', $builtin);
    }

    public function testArrayChangeKeyCaseJitHelperMatchesVmArraySemantics(): void
    {
        $ht = new HashTable();
        $val = new Variable(Variable::TYPE_INTEGER);
        $val->int(1);
        $ht->add('Foo', $val);
        $ht->addIndex(2, $val);

        $lower = ArrayChangeKeyCaseJitHelper::changeKeyCase($ht, StdlibConstants::CASE_LOWER);
        $this->assertSame(1, $lower->find('foo')?->resolveIndirect()->toInt());
        $this->assertSame(1, $lower->findIndex(2)?->resolveIndirect()->toInt());

        $upper = ArrayChangeKeyCaseJitHelper::changeKeyCase($ht, StdlibConstants::CASE_UPPER);
        $this->assertSame(1, $upper->find('FOO')?->resolveIndirect()->toInt());
    }
}
