<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** User-script AOT preg routes through ext/standard JitPregMatchKernel (#16075, #19399). */
final class PregMatchUserScriptLlvmTest extends TestCase
{
    public function testPregMatchRuntimeDefersNestedJitForUserScript(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('UserScriptAotDeferNestedJit::shouldDefer', $source);
        $this->assertStringContainsString('JitPregMatchKernel::implement', $source);
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
