<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PendingHeaders: always NestedJIT PendingHeadersJitHelper — no thin stubs (#9545, #20930).
 */
final class PendingHeadersRuntimeShrinkTest extends TestCase
{
    public function testPendingHeadersRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersRuntime.php');
        $this->assertStringContainsString('PendingHeadersJitBridge::implement', $runtime);
        $this->assertStringNotContainsString('PendingHeadersStandaloneLlvm', $runtime);
        $this->assertLessThan(35, substr_count($runtime, "\n"), 'PendingHeadersRuntime should be a thin router');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersStandaloneLlvm.php');
    }

    public function testBridgeAlwaysUsesNestedJitHelperNoThinStubs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersJitBridge.php');
        $this->assertStringContainsString('PendingHeadersJitHelper', $source);
        $this->assertStringContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
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
}
