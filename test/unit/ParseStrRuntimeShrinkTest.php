<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * parse_str() LLVM micro-runtime shrink guard (#6308 phase 2).
 *
 * Runtime superglobals still link __phpc_parse_str_* until ParseStrEngine is native-callable from AOT.
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

    public function testStringParseStrRoutesJitEmbedThroughParseStrRuntime(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringParseStr.php');
        $this->assertStringContainsString('ParseStrRuntime::implement', $source);
        $this->assertStringContainsString('StringParseStrJit::ensureSubhelpers', $source);
        $this->assertStringContainsString('StringParseStrJit::implement', $source);
        $this->assertLessThan(35, \substr_count($source, "\n") + 1);
    }

    public function testParseStrJitHelperDelegatesToParseStrEngine(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/ParseStrJitHelper.php');
        $this->assertStringContainsString('ParseStrEngine::parse', $source);
        $this->assertStringContainsString('VmParseStr::mergeInto', $source);
    }

    public function testSpineBundleIncludesParseStrPhpJitPath(): void
    {
        $spine = (string) file_get_contents($this->repoRoot.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ParseStrJitHelper.php', $spine);
        $this->assertStringContainsString('ParseStrRuntime.php', $spine);
        $this->assertStringContainsString('StringParseStrJit.php', $spine);
    }
}
