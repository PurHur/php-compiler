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
}
