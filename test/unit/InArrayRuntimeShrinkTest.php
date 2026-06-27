<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\InArrayJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** in_array() JIT routes through InArrayJitHelper PHP not ArrayBuiltinHelper LLVM (#12503). */
final class InArrayRuntimeShrinkTest extends TestCase
{
    public function testInArrayRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/InArrayRuntime.php');
        $this->assertStringContainsString('InArrayJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::inArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/in_array.php');
        $this->assertStringContainsString('InArrayRuntime::inArray', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::inArray', $builtin);
    }

    public function testInArrayJitHelperLooseMatch(): void
    {
        $haystack = new HashTable();
        $v = new Variable();
        $v->string('1');
        $haystack->addIndex(0, $v);

        $needle = new Variable();
        $needle->int(1);

        $this->assertTrue(InArrayJitHelper::contains($needle, $haystack, false));
        $this->assertFalse(InArrayJitHelper::contains($needle, $haystack, true));
    }

    public function testInArrayJitHelperStrictStringMatch(): void
    {
        $haystack = new HashTable();
        $v = new Variable();
        $v->string('foo');
        $haystack->addIndex(0, $v);

        $needle = new Variable();
        $needle->string('foo');

        $this->assertTrue(InArrayJitHelper::contains($needle, $haystack, true));
    }
}
