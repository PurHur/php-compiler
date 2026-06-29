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
        $this->assertStringContainsString('VmParseStr::mergeInto', $source);
        $this->assertStringContainsString('parseCookieHeaderInto', $source);
    }

    public function testParseStrNativeLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/ParseStrNativeLlvm.php');
        $userScript = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/SuperglobalRefreshUserScriptLlvm.php');
        $this->assertStringContainsString('ParseStrRuntime::ensureLinked', $userScript);
        $this->assertStringNotContainsString('ParseStrNativeLlvm', $userScript);
        $this->assertStringNotContainsString('__phpc_parse_str_', $userScript);
    }

    public function testSpineBundleIncludesParseStrPhpJitPath(): void
    {
        $spine = (string) file_get_contents($this->repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ParseStrJitHelper.php', $spine);
        $this->assertStringContainsString('ParseStrRuntime.php', $spine);
        $this->assertStringContainsString('StringParseStr.php', $spine);
        $this->assertStringNotContainsString('ParseStrNativeLlvm.php', $spine);
        $this->assertStringNotContainsString('StringParseStrJit.php', $spine);
    }

    public function testParseStrBridgeAppendsBlocksOnDeclaredFunction(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/ParseStrRuntime.php');
        $this->assertStringContainsString('$early = $fn->appendBasicBlock', $source);
        $this->assertStringNotContainsString('BasicBlockHelper::append($context, \'parse_str_bridge', $source);
        $this->assertStringContainsString('JitNestedHelperCoerce::coerceArgForHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
    }
}
