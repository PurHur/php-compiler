<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Thin standalone AOT preg routes through PregJitHelper NestedJIT — no Kernel stubs (#21212).
 */
final class PregMatchUserScriptLlvmTest extends TestCase
{
    public function testPregMatchRuntimeUsesNestedJitNotKernelStubs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('JitPregMatchKernel', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('PregMatchUserScriptLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/PregMatchUserScriptLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitPregMatchKernel.php');
    }

    public function testRuntimeLinksPregBeforeMainCompile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Runtime.php');
        $this->assertStringContainsString('if ($needsPregPrelink) {', $source);
        $this->assertStringContainsString('PregJitHelper via PregMatchRuntime', $source);
        $this->assertStringContainsString('StringPregMatch::ensureLinked($context);', $source);
        $this->assertStringNotContainsString('JitPregMatchKernel stubs', $source);
        $this->assertStringNotContainsString('deferUserScriptAotInit', $source);
    }

    public function testSpineBundleDropsPregMatchKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PregMatchRuntime.php', $spine);
        $this->assertStringContainsString('PregJitHelper.php', $spine);
        $this->assertStringNotContainsString('JitPregMatchKernel.php', $spine);
        $this->assertStringNotContainsString('PregMatchUserScriptLlvm.php', $spine);
    }
}
