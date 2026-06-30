<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * parse_str() LLVM micro-runtime shrink guard (#6308 phase 2, #13429).
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
        $this->assertLessThan(45, \substr_count($source, "\n") + 1);

        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/StringParseStrJit.php');
    }

    public function testParseStrJitHelperDelegatesToParseStrEngine(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/ParseStrJitHelper.php');
        $this->assertStringContainsString('ParseStrEngine::parse', $source);
        $this->assertStringContainsString('parseDelimitedIntoNative', $source);
        $this->assertStringContainsString('VmParseStr::mergeInto', $source);
        $this->assertStringContainsString('parseCookieHeaderInto', $source);
    }

    public function testParseStrNativeMergeBridgeUsesNativeHelpers(): void
    {
        $runtime = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringContainsString('PARSE_INTO_NATIVE_HELPER', $runtime);
        $this->assertStringContainsString('ptrToI64', $runtime);

        $helper = (string) file_get_contents($this->repoRoot.'/ext/standard/ParseStrJitHelper.php');
        $this->assertStringContainsString('parseIntoNative', $helper);
        $this->assertStringContainsString('phpc_native_ht_set_string_key', $helper);
        $this->assertFileExists($this->repoRoot.'/lib/JIT/Builtin/ParseStrNativeOpsJit.php');

        // User-script refresh routes via ParseStrRuntime init-safe bridge (#13900).
        $userScript = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/SuperglobalRefreshUserScriptLlvm.php');
        $this->assertStringContainsString('ParseStrRuntime::ensureLinked', $userScript);
        $this->assertStringContainsString('__compiler_parse_str', $userScript);
        $this->assertStringContainsString('emitUserScriptDelimitedParse', (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php'));
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/ParseStrNativeLlvm.php');
        $this->assertFileExists($this->repoRoot.'/lib/JIT/Builtin/ParseStrUserScriptDelimitedJit.php');
    }

    public function testSpineBundleIncludesParseStrPhpJitPath(): void
    {
        $spine = (string) file_get_contents($this->repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ParseStrJitHelper.php', $spine);
        $this->assertStringContainsString('ParseStrRuntime.php', $spine);
        $this->assertStringContainsString('StringParseStr.php', $spine);
        $this->assertStringContainsString('ParseStrNativeOpsJit.php', $spine);
        $this->assertStringContainsString('ParseStrUserScriptDelimitedJit.php', $spine);
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
        $this->assertStringContainsString('PARSE_INTO_NATIVE_HELPER', $source);
        $this->assertStringContainsString('ptrToI64', $source);
        $this->assertStringContainsString('$early = $fn->appendBasicBlock', $source);
        $this->assertStringNotContainsString('BasicBlockHelper::append($context, \'parse_str_bridge', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce::coerceArgForHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
    }
}
