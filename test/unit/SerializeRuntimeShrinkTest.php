<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * serialize() JIT: SerializeNestedJitHelper Context-free NestedJIT (#9180, #20773, #27030).
 */
final class SerializeRuntimeShrinkTest extends TestCase
{
    public function testStringSerializeUsesNestedJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('SerializeNestedJitHelper', $source);
        $this->assertStringContainsString('SerializeObjectNestedJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('serialize_value_bridge_entry', $source);
        $this->assertStringContainsString('serialize_ht_bridge_entry', $source);
        $this->assertStringContainsString('serialize_object_bridge_entry', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementThinStandaloneStubs', $source);
        $this->assertStringNotContainsString('serialize_thin_stub', $source);
        $this->assertStringNotContainsString('StringSerializeJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringSerializeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringSerializeDoubleJit.php');
    }

    public function testSerializeNestedJitHelperIsContextFree(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SerializeNestedJitHelper.php');
        $this->assertStringContainsString('encodeHashtable', $source);
        $this->assertStringContainsString('exportKeyValuePairs', $source);
        $this->assertStringNotContainsString('VmSerialize::', $source);
        $this->assertStringNotContainsString('Superglobals', $source);
        $this->assertStringNotContainsString('->runtime->vm', $source);
        $this->assertStringNotContainsString('->iterateKeyed', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1, 'SerializeNestedJitHelper must stay NestedJIT-slim (#27030)');
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('implementDeferredInventoryStubs', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
    }

    public function testSpineBundleIncludesSerializePhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SerializeNestedJitHelper.php', $spine);
        $this->assertStringContainsString('SerializeObjectNestedJitHelper.php', $spine);
        $this->assertStringContainsString('StringSerialize.php', $spine);
        $this->assertStringNotContainsString('StringSerializeJit.php', $spine);
    }
}
