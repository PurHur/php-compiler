<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * unserialize() JIT: always UnserializeJitHelper NestedJIT — no thin stubs (#9163, #20785).
 */
final class UnserializeRuntimeShrinkTest extends TestCase
{
    public function testStringUnserializeUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('UnserializeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('unser_bridge_entry', $source);
        $this->assertStringContainsString('session_unser_entry', $source);
        $this->assertStringContainsString('JitValueBox::copyIntoPointer', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementThinStandaloneStubs', $source);
        $this->assertStringNotContainsString('unser_thin_stub', $source);
        $this->assertStringNotContainsString('session_unser_thin_stub', $source);
        $this->assertStringNotContainsString('StringUnserializeJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(270, \substr_count($source, "\n") + 1, 'StringUnserialize must stay a thin bridge (#20785)');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringUnserializeJit.php');
    }

    public function testUnserializeJitHelperDelegatesToVmUnserializeFormat(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UnserializeJitHelper.php');
        $this->assertStringContainsString('VmUnserializeFormat::decodeToVariableWithContext', $source);
        $this->assertStringContainsString('VmSessionSerializer::decodeWireHashTable', $source);
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('implementDeferredInventoryStubs', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementThinStandaloneStubs', $source);
    }

    public function testSpineBundleIncludesUnserializePhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('UnserializeJitHelper.php', $spine);
        $this->assertStringContainsString('StringUnserialize.php', $spine);
        $this->assertStringNotContainsString('StringUnserializeJit.php', $spine);
    }
}
