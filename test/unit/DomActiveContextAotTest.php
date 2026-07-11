<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** sg_vm_context global + DOM user-script defer path (#17391). */
final class DomActiveContextAotTest extends TestCase
{
    public function testRuntimeInitVmContextStoresActiveContextGlobal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/RuntimeInitVmContext.php');
        $this->assertStringContainsString('VmActiveContextLlvm::storeContext', $source);
    }

    public function testDomInstanceMethodRuntimeWiresActiveContextProxy(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodRuntime.php');
        $this->assertStringContainsString('ensureActiveContextProxy', $runtime);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $runtime);
    }

    public function testVmDomJitFrameFallsBackToActiveContextHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/VmDomJitFrame.php');
        $this->assertStringContainsString('VmActiveContextJitHelper::resolve', $source);
    }

    public function testThinStandaloneInitSchedulesDomRegistration(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/VmActiveContextInitLlvm.php');
        $this->assertStringContainsString('DomStandaloneAotInitRuntime::ABI_NAME', $source);
        $this->assertStringContainsString('RuntimeInitVmContext::emit', $source);
        $this->assertStringContainsString('emitPendingBeforeSeal', $source);
        $this->assertStringNotContainsString('supportsDomTokenList', $source);
    }

    public function testDomInstanceMethodJitDefersGenericProxiesForUserScript(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString('shouldDeferToVmClassMethodLowering', $source);
        $this->assertStringContainsString('DomDocumentCreateElement', $source);
        $this->assertStringContainsString('isUserScriptDomMethod', $source);
        $this->assertStringContainsString('loadhtml', $source);
    }

    public function testDomLoadHTMLRuntimeDefinesAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomLoadHTMLRuntime.php');
        $this->assertStringContainsString('DomLoadHTMLJitHelper::loadHTMLArgv', $source);
        $this->assertStringContainsString('__phpc_dom_load_html', $source);
    }

    public function testDomInstanceMethodUserScriptRegistersProxiesBeforeHelperCompile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodUserScriptLlvm.php');
        $bridgePos = strpos($source, 'function ensureBridge');
        $this->assertNotFalse($bridgePos);
        $bridgeBody = substr($source, $bridgePos);
        $proxyPos = strpos($bridgeBody, 'ensureNestedHelperProxies');
        $compilePos = strpos($bridgeBody, 'ensureMainModuleHelperCompiled');
        $this->assertNotFalse($proxyPos);
        $this->assertNotFalse($compilePos);
        $this->assertLessThan($compilePos, $proxyPos, 'nested helper proxies must register before main-module compile');
    }

    public function testDomInstanceMethodJitSeedsDomElementPropertyLayout(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString('ensureDomElementPropertyLayout', $source);
        $this->assertStringContainsString('nodeName', $source);
        $this->assertStringContainsString('tagName', $source);
    }
}
