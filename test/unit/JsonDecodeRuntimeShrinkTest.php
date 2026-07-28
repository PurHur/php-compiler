<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * json_decode() JIT: always JitVmHelperLink + JsonDecodeJitHelper PHP (#9359, #13228, #20829).
 */
final class JsonDecodeRuntimeShrinkTest extends TestCase
{
    public function testStringJsonDecodeUsesJitHelperNotLlvmWalker(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('JsonDecodeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('json_decode_bridge_entry', $source);
        $this->assertStringContainsString('json_validate_bridge_entry', $source);
        $this->assertStringContainsString('resultTag', $source);
        $this->assertStringContainsString('returnValue', $source);
        $this->assertStringNotContainsString('__compiler_json_decode_tag', $source);
        $this->assertStringNotContainsString('StringJsonDecodeJit', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('StringJsonDecodeInventoryStubs', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(450, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecodeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecodeInventoryStubs.php');
    }

    public function testJsonDecodeJitHelperUsesNativeHashtableMaterializer(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JsonDecodeJitHelper.php');
        $this->assertStringContainsString('function decode(string $payload): int', $source);
        $this->assertStringContainsString('phpc_native_ht_set_string_key_long', $source);
        $this->assertStringContainsString('VmJsonFormat::decode(', $source);
        $this->assertStringContainsString('resultTag', $source);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('ensureNativeHtInternalProxies', $bridge);
        $this->assertStringContainsString('phpc_native_ht_set_long_at', $bridge);
        $validate = (string) file_get_contents(__DIR__.'/../../ext/standard/JsonValidateJitHelper.php');
        $this->assertStringContainsString('VmJsonScanner::validate', $validate);
        $this->assertStringContainsString('VmJson::lastError', $validate);
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
        $this->assertStringContainsString('JsonValidateJitHelper', $source);
        $this->assertStringContainsString('DECODE_HELPER_PATH', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $source);
    }

    public function testSpineBundleIncludesJsonDecodePhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JsonValidateJitHelper.php', $spine);
        $this->assertStringContainsString('JsonDecodeJitHelper.php', $spine);
        $this->assertStringContainsString('StringJsonDecode.php', $spine);
        $this->assertStringNotContainsString('StringJsonDecodeInventoryStubs.php', $spine);
        $this->assertStringNotContainsString('StringJsonDecodeJit.php', $spine);
    }
}
