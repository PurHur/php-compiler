<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** strtok JIT routes through StrtokJitHelper PHP, not hand-written LLVM (#9812). */
final class StrtokJitRuntimeShrinkTest extends TestCase
{
    public function testStrtokJitHelperDelegatesToVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/StrtokJitHelper.php');
        $this->assertStringContainsString('VmString::strtok', $source);
        $this->assertStringContainsString('VmString::strtokResetState', $source);
        $this->assertStringContainsString('VmString::strtokInitState', $source);
    }

    public function testStringStrtokRoutesThroughStrtokJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtok.php');
        $this->assertStringContainsString('StrtokJitHelper', $source);
        $this->assertStringNotContainsString('__phpc_strtok_buf', $source);
        $this->assertStringNotContainsString('emitStrtok', $source);
        $this->assertStringNotContainsString('emitReset', $source);
        $this->assertStringNotContainsString('emitInit', $source);

        $jitShim = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtokJit.php');
        $this->assertLessThan(20, \substr_count($jitShim, "\n"), 'StringStrtokJit must be a thin shim');
    }

    public function testJitStrtokRoutesThroughStringStrtok(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/strtok.php');
        $this->assertStringContainsString('StringStrtok::ensureLinked', $source);
    }

    public function testStrtokJitHelperSemanticsMatchVmString(): void
    {
        \PHPCompiler\ext\standard\VmString::strtokResetState();
        $this->assertSame('a', \PHPCompiler\ext\standard\StrtokJitHelper::tokenize('a,b,c', ',', 1));
        $this->assertSame('b', \PHPCompiler\ext\standard\StrtokJitHelper::tokenize(null, ',', 0));
        $this->assertSame('c', \PHPCompiler\ext\standard\StrtokJitHelper::tokenize(null, ',', 0));
        $this->assertNull(\PHPCompiler\ext\standard\StrtokJitHelper::tokenize(null, ',', 0));
        \PHPCompiler\ext\standard\StrtokJitHelper::tokenize('x:y', ':', 1);
        $this->assertSame('y', \PHPCompiler\ext\standard\StrtokJitHelper::tokenize(null, ':', 0));
    }
}
