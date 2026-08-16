<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UrlRewriterApplyRuntime must NestedJIT UrlRewriterApplyJitHelper PHP — no LLVM href scan (#31099).
 */
final class UrlRewriterApplyRuntimeShrinkTest extends TestCase
{
    public function testUrlRewriterApplyRuntimeUsesJitHelperNotLlvmHrefScan(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UrlRewriterApplyRuntime.php');
        $this->assertStringContainsString('UrlRewriterApplyJitHelper', $source);
        $this->assertStringContainsString('VmUrlRewriterHrefApply', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('ensureNestedJitBridge', $source);
        $this->assertStringContainsString('ensureIdentityStub', $source);
        $this->assertStringContainsString('ura_identity_stub', $source);
        $this->assertStringContainsString('OutputRewriteVarsStorage::stringFromGlobal', $source);
        $this->assertStringNotContainsString('emitLlvmHrefApplyBody', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
        $this->assertStringNotContainsString("lookupFunction('strchr')", $source);
        $this->assertStringNotContainsString("lookupFunction('memchr')", $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/UrlRewriterApplyJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/VmUrlRewriterHrefApply.php');
        $lines = \substr_count($source, "\n") + 1;
        $this->assertLessThan(280, $lines, 'UrlRewriterApplyRuntime should stay a thin bridge (#31099/#31663)');
    }

    public function testObOutputJitBridgeNestedJitsApplyOnRewritePathEvenIfObAlreadyLinked(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObOutputJitBridge.php');
        $this->assertStringContainsString('UrlRewriterApplyRuntime::ensureNestedJitBridge', $bridge);
        // Early-return path must still NestedJIT apply (#31099 — init Ob must not skip it).
        $this->assertMatchesRegularExpression(
            '/countBasicBlocks\(\) > 0.*?ensureNestedJitBridge/s',
            $bridge
        );
    }
}
