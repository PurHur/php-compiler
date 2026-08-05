<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * strtok JIT/AOT: LLVM module-global state (#27645); VmString remains VM SSOT.
 * NestedJIT StrtokJitHelper aborts under thin AOT (#26906) — keep helper for
 * semantic parity checks only.
 */
final class StrtokJitRuntimeShrinkTest extends TestCase
{
    public function testStrtokJitHelperDelegatesToVmString(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/StrtokJitHelper.php');
        $this->assertStringContainsString('VmString::strtok', $source);
        $this->assertStringContainsString('VmString::strtokResetState', $source);
        $this->assertStringContainsString('VmString::strtokInitState', $source);
    }

    public function testStringStrtokRoutesThroughLlvmModuleGlobals(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtok.php');
        $this->assertStringContainsString('StringStrtokJit::implement', $source);

        $jit = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtokJit.php');
        $this->assertStringContainsString('__phpc_strtok_buf', $jit);
        $this->assertStringContainsString('emitStrtok', $jit);
        $this->assertStringContainsString('emitReset', $jit);
        $this->assertStringContainsString('emitInit', $jit);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $jit);
        $this->assertStringNotContainsString('StrtokJitHelper::', $jit);
    }

    public function testJitStrtokRoutesThroughStringStrtok(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/strtok.php');
        $this->assertStringContainsString('StringStrtok::ensureLinked', $source);
        $this->assertStringNotContainsString('tryFoldCompileTime', $source);
    }

    public function testStrtokJitHelperSemanticsMatchVmString(): void
    {
        \PHPCompiler\ext\standard\VmString::strtokResetState();
        $this->assertSame('a', \PHPCompiler\ext\standard\StrtokJitHelper::tokenize('a,b,c', ',', 1, 0, 0));
        $this->assertSame('b', \PHPCompiler\ext\standard\StrtokJitHelper::tokenize('', ',', 0, 1, 0));
        $this->assertSame('c', \PHPCompiler\ext\standard\StrtokJitHelper::tokenize('', ',', 0, 1, 0));
        $this->assertFalse(\PHPCompiler\ext\standard\StrtokJitHelper::tokenize('', ',', 0, 1, 0));
        \PHPCompiler\ext\standard\StrtokJitHelper::tokenize('x:y', ':', 1, 0, 0);
        $this->assertSame('y', \PHPCompiler\ext\standard\StrtokJitHelper::tokenize('', ':', 0, 1, 0));
        $this->assertFalse(\PHPCompiler\ext\standard\StrtokJitHelper::tokenize('a.b.c', '', 1, 0, 1));
    }
}
