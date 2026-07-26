<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * DomStandaloneAotInit NestedJIT via JitVmHelperLink::ensureCompiled (#23374 / peer #23325).
 */
final class DomStandaloneAotInitKernelShrinkTest extends TestCase
{
    public function testUserScriptLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/DomStandaloneAotInitUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/dom/JitDomStandaloneAotInitKernel.php');

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomStandaloneAotInitKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\dom;', $kernel);
        $this->assertStringContainsString('final class JitDomStandaloneAotInitKernel', $kernel);
        $this->assertStringNotContainsString('DomStandaloneAotInitUserScriptLlvm', $kernel);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomStandaloneAotInitRuntime.php');
        $this->assertStringContainsString('JitDomStandaloneAotInitKernel', $runtime);
        $this->assertStringNotContainsString('DomStandaloneAotInitUserScriptLlvm', $runtime);
    }

    public function testKernelUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomStandaloneAotInitKernel.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedVmActiveContextLlvm;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 2)', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
    }

    public function testSpineBundleIncludesDomStandaloneAotInitKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitDomStandaloneAotInitKernel.php', $spine);
        $this->assertStringNotContainsString('DomStandaloneAotInitUserScriptLlvm.php', $spine);
    }

    public function testThinPathGatesOnIsThinStandaloneAotMain(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomStandaloneAotInitKernel.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }
}
