<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * HashContextEmbedBridge NestedJIT via JitVmHelperLink::ensureCompiled (#23189 / peer #23174).
 */
final class HashContextEmbedBridgeRuntimeShrinkTest extends TestCase
{
    public function testHashContextEmbedBridgeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HashContextEmbedBridge.php');
        $this->assertStringContainsString('HashContextJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testHashContextJitHelperDefinesInitUpdateFinalCopy(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/hash/HashContextJitHelper.php');
        $this->assertStringContainsString('function init(', $source);
        $this->assertStringContainsString('function update(', $source);
        $this->assertStringContainsString('function finalize(', $source);
        $this->assertStringContainsString('function markFinalized(', $source);
        $this->assertStringContainsString('function copy(', $source);
    }
}
