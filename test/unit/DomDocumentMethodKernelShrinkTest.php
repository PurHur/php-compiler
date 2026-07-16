<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** DomDocumentMethod user-script AOT kernel quarantined in ext/dom (#19496). */
final class DomDocumentMethodKernelShrinkTest extends TestCase
{
    public function testUserScriptLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/DomDocumentMethodUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/dom/JitDomDocumentMethodKernel.php');

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomDocumentMethodKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\dom;', $kernel);
        $this->assertStringContainsString('final class JitDomDocumentMethodKernel', $kernel);
        $this->assertStringNotContainsString('DomDocumentMethodUserScriptLlvm', $kernel);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomLoadHTMLRuntime.php');
        $this->assertStringContainsString('JitDomDocumentMethodKernel', $runtime);
        $this->assertStringNotContainsString('DomDocumentMethodUserScriptLlvm', $runtime);
    }

    public function testSpineBundleIncludesDomDocumentMethodKernelNotBuiltinUserScriptLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitDomDocumentMethodKernel.php', $spine);
        $this->assertStringNotContainsString('DomDocumentMethodUserScriptLlvm.php', $spine);
    }
}
