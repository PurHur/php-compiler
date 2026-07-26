<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * DomInstanceMethod NestedJIT via JitVmHelperLink::ensureCompiled (#23361 / peer #23325).
 */
final class DomInstanceMethodKernelShrinkTest extends TestCase
{
    public function testUserScriptLlvmMovedToExtKernel(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/dom/JitDomInstanceMethodKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodRuntime.php');

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomInstanceMethodKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\dom;', $kernel);
        $this->assertStringContainsString('final class JitDomInstanceMethodKernel', $kernel);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodRuntime.php');
        $this->assertStringContainsString('JitDomInstanceMethodKernel', $runtime);
        $this->assertStringContainsString('JitDomInstanceMethodKernel::ensureBridge', $runtime);
    }

    public function testKernelUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomInstanceMethodKernel.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('VmDomInstanceInvoke.php', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
    }

    public function testSpineBundleIncludesDomInstanceMethodKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitDomInstanceMethodKernel.php', $spine);
        $this->assertStringContainsString('DomInstanceMethodRuntime.php', $spine);
    }

    public function testThinPathGatesOnIsThinStandaloneAotMain(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomInstanceMethodKernel.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }
}
