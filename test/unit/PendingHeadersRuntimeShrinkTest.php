<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PendingHeaders: JitVmHelperLink + PendingHeadersJitHelper PHP (#9545, #20930, #21005, #22034).
 */
final class PendingHeadersRuntimeShrinkTest extends TestCase
{
    public function testPendingHeadersRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersRuntime.php');
        $this->assertStringContainsString('PendingHeadersJitBridge::implement', $runtime);
        $this->assertStringContainsString('PendingHeadersJitBridge::fillThinAotLinkStubs', $runtime);
        $this->assertStringNotContainsString('PendingHeadersStandaloneLlvm', $runtime);
        $this->assertLessThan(45, substr_count($runtime, "\n"), 'PendingHeadersRuntime should be a thin router');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersStandaloneLlvm.php');
    }

    public function testBridgeAlwaysUsesNestedJitHelperNoThinStubs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersJitBridge.php');
        $this->assertStringContainsString('PendingHeadersJitHelper', $source);
        $this->assertStringContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('fillThinAotLinkStubs', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementDeferredInventoryStubs', $source);
        $this->assertStringNotContainsString('ph_sent_inv_stub', $source);
        $this->assertStringNotContainsString('ph_add_inv_stub', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testSpineBundleIncludesPendingHeadersPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PendingHeadersJitHelper.php', $spine);
        $this->assertStringContainsString('PendingHeadersJitBridge.php', $spine);
        $this->assertStringContainsString('PendingHeadersRuntime.php', $spine);
    }

    public function testHelperUsesHostGetenvNotKernel(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/PendingHeadersJitHelper.php');
        $this->assertStringContainsString('@\\getenv', $helper);
        $this->assertStringContainsString('environGet', $helper);
        $this->assertStringNotContainsString('phpc_getenv_kernel', $helper);
        $this->assertStringNotContainsString('JitGetenvKernel', $helper);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersJitBridge.php');
        $this->assertStringContainsString('StringGetenv::ensureNativeHtInternalProxies', $bridge);
        $this->assertStringContainsString('#29313', $bridge);
    }
}
