<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Dom instance-method + standalone AOT init thin kernels in ext/dom (#17391, #19487, #20214). */
final class DomInstanceMethodRuntimeShrinkTest extends TestCase
{
    public function testDomInstanceMethodRuntimeUsesExtKernelForUserScript(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodRuntime.php');
        $this->assertStringContainsString('JitDomInstanceMethodKernel', $source);
        $this->assertStringNotContainsString('DomInstanceMethodUserScriptLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/dom/JitDomInstanceMethodKernel.php');
    }

    public function testDomStandaloneAotInitRuntimeUsesExtKernelForUserScript(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomStandaloneAotInitRuntime.php');
        $this->assertStringContainsString('JitDomStandaloneAotInitKernel', $source);
        $this->assertStringNotContainsString('DomStandaloneAotInitUserScriptLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/DomStandaloneAotInitUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/dom/JitDomStandaloneAotInitKernel.php');
    }

    public function testSpineBundleIncludesDomInstanceMethodKernels(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitDomInstanceMethodKernel.php', $spine);
        $this->assertStringContainsString('JitDomStandaloneAotInitKernel.php', $spine);
        $this->assertStringNotContainsString('DomInstanceMethodUserScriptLlvm.php', $spine);
        $this->assertStringNotContainsString('DomStandaloneAotInitUserScriptLlvm.php', $spine);
    }

    public function testDomKernelsGateThinPathOnIsThinStandaloneAotMain(): void
    {
        foreach ([
            __DIR__.'/../../ext/dom/JitDomInstanceMethodKernel.php',
            __DIR__.'/../../ext/dom/JitDomStandaloneAotInitKernel.php',
        ] as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('isThinStandaloneAotMain', $source);
            $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        }
    }
}
