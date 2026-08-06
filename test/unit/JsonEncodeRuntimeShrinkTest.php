<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * json_encode() JIT: always JitVmHelperLink + JsonEncodeNestedJitHelper PHP (#9267, #13239, #20816).
 */
final class JsonEncodeRuntimeShrinkTest extends TestCase
{
    public function testStringJsonEncodeUsesJitHelperNotLlvmWalker(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('JsonEncodeNestedJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('json_encode_value_bridge_entry', $source);
        $this->assertStringContainsString('json_encode_array_bridge_entry', $source);
        $this->assertStringNotContainsString('StringJsonEncodeJit', $source);
        $this->assertStringNotContainsString('implementValue', $source);
        $this->assertStringNotContainsString('implementArray', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('StringJsonEncodeInventoryStubs', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(180, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncodeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncodeInventoryStubs.php');
    }

    public function testJsonEncodeNestedJitHelperIsContextFree(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JsonEncodeNestedJitHelper.php');
        // Thin AOT NestedJIT cannot touch Context/runtime->vm (#27020) or VmJson::export.
        $this->assertStringContainsString('encodeHashtable', $source);
        $this->assertStringContainsString('exportKeyValuePairs', $source);
        $this->assertStringContainsString("\$out = \$packed ? '[' : '{'", $source);
        $this->assertStringNotContainsString('VmJson::export', $source);
        $this->assertStringNotContainsString('VmJsonFormat::encodeExported', $source);
        $this->assertStringNotContainsString('VmActiveContextJitHelper::resolve', $source);
        $this->assertStringNotContainsString('->runtime->vm', $source);
        $this->assertStringNotContainsString('->runStackFrames(', $source);
        $this->assertStringNotContainsString('iterateKeyed', $source);
        $this->assertLessThan(160, \substr_count($source, "\n") + 1, 'JsonEncodeNestedJitHelper must stay NestedJIT-slim (#27020)');
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('JsonEncodeNestedJitHelper', $bridge);
        $this->assertStringContainsString('jsonencodenestedjithelper::encodevalue', strtolower(
            (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php')
        ));
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
        $fold = (string) file_get_contents(__DIR__.'/../../ext/standard/JitJsonEncodeCompileTime.php');
        $this->assertStringContainsString('tryCompileTimeArrayFromArrayReplaceRecursive', $fold);
        $this->assertStringContainsString('array_replace_recursive', $fold);
        $this->assertStringContainsString('tryCompileTimeArrayFromArrayFlip', $fold);
        $this->assertStringContainsString("'array_flip'", $fold);
        $this->assertStringContainsString('VmArray::flip', $fold);
        $this->assertStringContainsString('tryCompileTimeArrayFromArrayChangeKeyCase', $fold);
        $this->assertStringContainsString("'array_change_key_case'", $fold);
        $this->assertStringContainsString('VmArray::changeKeyCase', $fold);
        $this->assertStringContainsString('tryCompileTimeIntFromSlot', $fold);
        $this->assertStringContainsString('tryCompileTimeArrayFromParseUrl', $fold);
        $this->assertStringContainsString("'parse_url'", $fold);
        $this->assertStringContainsString('VmString::parseUrl', $fold);
        // #27181 — json_encode(preg_filter(lit…)) fold (peer #27080 preg_split).
        $this->assertStringContainsString('tryCompileTimeArrayFromPregFilter', $fold);
        $this->assertStringContainsString('tryEncodePregFilterScalar', $fold);
        $this->assertStringContainsString("'preg_filter'", $fold);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPregReplaceCompileTime.php');
        $this->assertFileExists(__DIR__.'/../../test/repro/issue_27181_aot_preg_filter.php');
    }

    public function testSpineBundleIncludesJsonEncodePhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JsonEncodeNestedJitHelper.php', $spine);
        $this->assertStringContainsString('StringJsonEncode.php', $spine);
        $this->assertStringNotContainsString('StringJsonEncodeInventoryStubs.php', $spine);
        $this->assertStringNotContainsString('StringJsonEncodeJit.php', $spine);
    }
}
