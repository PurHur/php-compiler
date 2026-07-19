<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * serialize() JIT: always JitVmHelperLink + SerializeJitHelper PHP (#9180, #13311, #20773).
 */
final class SerializeRuntimeShrinkTest extends TestCase
{
    public function testStringSerializeUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('SerializeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('serialize_value_bridge_entry', $source);
        $this->assertStringContainsString('serialize_ht_bridge_entry', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementThinStandaloneStubs', $source);
        $this->assertStringNotContainsString('serialize_thin_stub', $source);
        $this->assertStringNotContainsString('StringSerializeJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringSerialize must be a thin bridge (#20773)');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringSerializeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringSerializeDoubleJit.php');
    }

    public function testSerializeJitHelperDelegatesToVmSerialize(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SerializeJitHelper.php');
        $this->assertStringContainsString('VmSerialize::serializeValue', $source);
        $this->assertStringContainsString('encodeHashtable', $source);
        $this->assertStringNotContainsString('->iterateKeyed', $source);
        $this->assertStringNotContainsString('serializeSessionWireValue', $source);
        $this->assertLessThan(55, \substr_count($source, "\n"), 'SerializeJitHelper must stay NestedJIT-slim (#20773)');
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
        $this->assertStringContainsString('SerializeJitHelper.php', $spine);
        $this->assertStringContainsString('StringSerialize.php', $spine);
        $this->assertStringNotContainsString('StringSerializeJit.php', $spine);
    }
}
