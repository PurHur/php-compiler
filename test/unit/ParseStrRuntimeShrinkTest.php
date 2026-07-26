<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * parse_str() LLVM micro-runtime shrink guard (#6308 phase 2, #13429, #20132).
 */
final class ParseStrRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcParseStrCRuntimeStillAbsent(): void
    {
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_parse_str.c');
        $linker = (string) file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_parse_str.c', $linker);
    }

    public function testCompileTimeJitPathUsesParseStrEngine(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/JitParseStr.php');
        $this->assertStringContainsString('ParseStrEngine::parse', $source);
        $this->assertStringContainsString('JitParseStrMaterializer', $source);
    }

    public function testParseStrEngineExposesDelimitedParserForSuperglobals(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/ParseStrEngine.php');
        $this->assertStringContainsString('parseDelimited', $source);
        $this->assertStringContainsString('cookiePairDecode', $source);
    }

    public function testStringParseStrRoutesAllLoadTypesThroughParseStrRuntime(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringParseStr.php');
        $this->assertStringContainsString('ParseStrRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('StringParseStrJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertLessThan(50, \substr_count($source, "\n") + 1);

        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/StringParseStrJit.php');
    }

    public function testParseStrJitHelperDelegatesToParseStrEngine(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/ParseStrJitHelper.php');
        $native = (string) file_get_contents($this->repoRoot.'/ext/standard/ParseStrNativeJitHelper.php');
        $this->assertStringContainsString('ParseStrEngine::parse', $source);
        $this->assertStringContainsString('ParseStrNativeJitHelper', $source);
        $this->assertStringContainsString('parseIntoNative', $native);
        $this->assertStringContainsString('phpc_native_ht_set_string_key', $native);
        $this->assertFileExists($this->repoRoot.'/lib/JIT/Builtin/ParseStrNativeOpsJit.php');

        // User-script refresh: libc C strings → __compiler_parse_str / cookie bridge (#18855).
        $userScript = (string) file_get_contents($this->repoRoot.'/ext/standard/JitSuperglobalRefreshKernel.php');
        $this->assertStringContainsString('ParseStrRuntime::ensureUserScriptLinked', $userScript);
        $this->assertStringContainsString('__compiler_parse_str', $userScript);
        $this->assertStringContainsString('__compiler_parse_cookie_header', $userScript);
        $this->assertStringNotContainsString('ParseStrUserScriptDelimitedJit', $userScript);
        $this->assertStringNotContainsString('__phpc_parse_str_parse_delimited_pairs', $userScript);
        $parseStrRuntime = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringContainsString('implementUserScriptCstrDelimitedBridge', $parseStrRuntime);
        $this->assertStringContainsString('JitParseStrUserScriptCstrKernel', $parseStrRuntime);
        $this->assertStringContainsString('encodedStringToCstr', $parseStrRuntime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $parseStrRuntime);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/ParseStrUserScriptDelimitedJit.php');
        $this->assertFileExists($this->repoRoot.'/ext/standard/JitParseStrUserScriptCstrKernel.php');
        $stringParseStr = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringParseStr.php');
        $this->assertStringContainsString('ensureUserScriptLinked', $stringParseStr);
        $this->assertStringContainsString('isThinStandaloneAotMain', $stringParseStr);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $stringParseStr);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/ParseStrNativeLlvm.php');
        $this->assertFileExists($this->repoRoot.'/lib/JIT/Builtin/MultipartRuntime.php');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/StringMultipartStandaloneLlvm.php');
        $this->assertFileExists($this->repoRoot.'/lib/Web/MultipartNativeJitHelper.php');
    }

    public function testParseStrUserScriptDelimitedJitDeleted(): void
    {
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/ParseStrUserScriptDelimitedJit.php');
        $this->assertFileExists($this->repoRoot.'/ext/standard/JitParseStrUserScriptCstrKernel.php');
    }

    public function testParseStrRuntimeRoutesUserScriptThroughCstrDelimitedBridge(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringContainsString('implementUserScriptParseBridge', $source);
        $this->assertStringContainsString('implementUserScriptCstrDelimitedBridge', $source);
        $this->assertStringContainsString('JitParseStrUserScriptCstrKernel::ensureSubhelpers', $source);
        $this->assertStringNotContainsString('emitUserScriptDelimitedParse', $source);
        $this->assertStringContainsString('_work_v8', $source);
    }

    public function testStringParseStrRoutesUserScriptAotToNativeHelperLink(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringParseStr.php');
        $this->assertStringContainsString('ensureUserScriptLinked', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('shouldDefer', $source);
    }

    /** Issue #20533 — dead StreamIo defer stubs removed; thin AOT uses ensureUserScriptLinked only. */
    public function testParseStrRuntimeHasNoDeferredUserScriptRefreshStubs(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringNotContainsString('ensureDeferredStubsForUserScriptRefresh', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('_user_refresh_stub', $source);
        $this->assertStringContainsString('ensureUserScriptLinked', $source);
    }

    public function testParseStrBridgeReplacesDeferredStubBody(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringContainsString('clearFunctionBody', $source);
        $this->assertStringContainsString('userScriptBridgeBodyComplete', $source);
        $this->assertStringContainsString('implementUserScriptIfMissing', $source);
    }

    public function testSpineBundleIncludesParseStrPhpJitPath(): void
    {
        $spine = (string) file_get_contents($this->repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ParseStrJitHelper.php', $spine);
        $this->assertStringContainsString('ParseStrRuntime.php', $spine);
        $this->assertStringContainsString('StringParseStr.php', $spine);
        $this->assertStringContainsString('ParseStrNativeOpsJit.php', $spine);
        $this->assertStringNotContainsString('ParseStrUserScriptDelimitedJit.php', $spine);
        $this->assertStringContainsString('JitParseStrUserScriptCstrKernel.php', $spine);
        $this->assertStringNotContainsString('ParseStrNativeLlvm.php', $spine);
        $this->assertStringContainsString('phpc_native_ht_alloc.php', $spine);
        $this->assertStringNotContainsString('StringParseStrJit.php', $spine);
    }

    public function testParseStrRuntimeRegistersNativeHtProxiesBeforeNestedCompile(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringContainsString('ensureNativeHtInternalProxies', $source);
        $this->assertStringContainsString('phpc_native_ht_set_string_key', $source);
        $this->assertStringContainsString('Call\\ExternalMethod', $source);
    }

    public function testParseStrNativeOpsJitUsesVariableValueField(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrNativeOpsJit.php');
        $this->assertStringContainsString('->value', $source);
        $this->assertStringNotContainsString('->getValue()', $source);
        $this->assertStringContainsString('JitLongArg::lower', $source);
        $this->assertStringContainsString('JitStringArg::lowerDominating', $source);
    }

    public function testJitMaterializesNestedStringParamsAtCalleeEntry(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT.php');
        $this->assertStringContainsString('prepareNestedJitCalleeParamArgument', $source);
        $this->assertStringContainsString('__string__separate', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
    }

    public function testParseStrBridgeAppendsBlocksOnDeclaredFunction(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringContainsString('implementUserScriptParseBridge', $source);
        $this->assertStringContainsString('ensureUserScriptLinked', $source);
        $this->assertStringContainsString('ptrToI64', $source);
        $this->assertStringContainsString('$early = $fn->appendBasicBlock', $source);
        $this->assertStringNotContainsString('BasicBlockHelper::append($context, \'parse_str_bridge', $source);
        $this->assertStringNotContainsString('emitUserScriptDelimitedParse', $source);
        $this->assertStringContainsString('JitParseStrUserScriptCstrKernel::ensureSubhelpers', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce::coerceArgForHelper', $source);
        $this->assertStringNotContainsString(
            'USER_SCRIPT_HELPER_PATH',
            $source,
            'user-script refresh must not nested-compile ParseStrNativeJitHelper before v8 bridge (#18832)'
        );
    }

    /** Issue #14150 / #23529 — parse_str() root keys map `.`, `+`, and space to `_` (php_register_variable). */
    public function testParseStrEngineNormalizesDotAndPlusRootKeys(): void
    {
        $dots = \PHPCompiler\ext\standard\ParseStrEngine::parse('a.b=1&a.c=2');
        $this->assertSame(['a_b' => '1', 'a_c' => '2'], $dots);

        $plus = \PHPCompiler\ext\standard\ParseStrEngine::parse('a+b=1');
        $this->assertSame(['a_b' => '1'], $plus);

        $space = \PHPCompiler\ext\standard\ParseStrEngine::parse('c d=2&x%20y=5');
        $this->assertSame(['c_d' => '2', 'x_y' => '5'], $space);

        $nested = \PHPCompiler\ext\standard\ParseStrEngine::parse('a[b+c]=1');
        $this->assertSame(['a' => ['b c' => '1']], $nested);

        $nestedSpace = \PHPCompiler\ext\standard\ParseStrEngine::parse('p[q r]=6');
        $this->assertSame(['p' => ['q r' => '6']], $nestedSpace);

        $nestedBase = \PHPCompiler\ext\standard\ParseStrEngine::parse('a.b[c]=1');
        $this->assertSame(['a_b' => ['c' => '1']], $nestedBase);
    }
}
