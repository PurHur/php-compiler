<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * json_encode() JIT: always JitVmHelperLink + JsonEncodeJitHelper PHP (#9267, #13239, #20816).
 */
final class JsonEncodeRuntimeShrinkTest extends TestCase
{
    public function testStringJsonEncodeUsesJitHelperNotLlvmWalker(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('JsonEncodeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('json_encode_value_bridge_entry', $source);
        $this->assertStringContainsString('json_encode_array_bridge_entry', $source);
        $this->assertStringNotContainsString('StringJsonEncodeJit', $source);
        $this->assertStringNotContainsString('implementValue', $source);
        $this->assertStringNotContainsString('implementArray', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('StringJsonEncodeInventoryStubs', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(160, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncodeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncodeInventoryStubs.php');
    }

    public function testJsonEncodeJitHelperDelegatesToVmJson(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JsonEncodeJitHelper.php');
        $this->assertStringContainsString('VmJson::export', $source);
        $this->assertStringContainsString('VmJsonFormat::encodeExported', $source);
        $this->assertStringContainsString('encodeHashtable', $source);
        $this->assertStringContainsString('VmActiveContextJitHelper::resolve', $source);
        $this->assertStringNotContainsString('->runStackFrames(', $source);
        $this->assertLessThan(70, \substr_count($source, "\n") + 1, 'JsonEncodeJitHelper must stay NestedJIT-slim (#20816)');
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $source);
    }

    public function testUserScriptAotFoldsInlineArrayLiterals(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitJsonEncodeCompileTime.php');
        $encode = (string) file_get_contents(__DIR__.'/../../ext/standard/json_encode.php');
        $this->assertStringContainsString('JitJsonEncodeCompileTime::tryEncode', $encode);
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('jitJsonEncodeValueOperand', $jit);
    }

    public function testSpineBundleIncludesJsonEncodePhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JsonEncodeJitHelper.php', $spine);
        $this->assertStringContainsString('StringJsonEncode.php', $spine);
        $this->assertStringNotContainsString('StringJsonEncodeInventoryStubs.php', $spine);
        $this->assertStringNotContainsString('StringJsonEncodeJit.php', $spine);
    }
}
