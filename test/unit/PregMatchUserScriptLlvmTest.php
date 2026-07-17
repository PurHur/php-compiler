<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Thin standalone AOT preg routes through ext/standard JitPregMatchKernel
 * via isThinStandaloneAotMain (#16075, #19399, #20178).
 */
final class PregMatchUserScriptLlvmTest extends TestCase
{
    public function testPregMatchRuntimeUsesThinStandaloneGateNotNestedJitDefer(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('JitPregMatchKernel::implement', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('PregMatchUserScriptLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/PregMatchUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPregMatchKernel.php');
    }

    public function testRuntimeLinksPregBeforeMainCompile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Runtime.php');
        $this->assertStringContainsString('if ($needsPregPrelink) {', $source);
        $this->assertStringContainsString('JitPregMatchKernel stubs', $source);
        $this->assertStringContainsString('StringPregMatch::ensureLinked($context);', $source);
        $this->assertStringNotContainsString('deferUserScriptAotInit', $source);
    }

    public function testSpineBundleIncludesPregMatchKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitPregMatchKernel.php', $spine);
        $this->assertStringContainsString('PregMatchRuntime.php', $spine);
        $this->assertStringNotContainsString('PregMatchUserScriptLlvm.php', $spine);
    }
}
