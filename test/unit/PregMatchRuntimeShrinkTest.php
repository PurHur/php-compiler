<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregJitHelper;
use PHPUnit\Framework\TestCase;

/** preg_* JIT: PregJitHelper via JitVmHelperLink::ensureCompiledBundle — no dishonest Kernel stubs (#9542, #21212, #24943). */
final class PregMatchRuntimeShrinkTest extends TestCase
{
    public function testStringPregMatchJitIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPregMatchJit.php');
        $this->assertStringContainsString('PregMatchRuntime', $source);
        $this->assertStringNotContainsString('StringPregMatchStandaloneLlvm::implement', $source);
        $this->assertStringNotContainsString('pcre2_match_8', $source);
        $this->assertStringNotContainsString('emitMatchEx', $source);
        // NestedJIT early-return for helper-TU emit (#26989 / #28096 regression) adds a few lines.
        $this->assertLessThan(95, \substr_count($source, "\n") + 1);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
    }

    public function testPregMatchRuntimeAlwaysUsesJitHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PregMatchRuntime.php');
        $this->assertStringContainsString('PregJitHelper', $source);
        $this->assertStringContainsString('replaceCallbackArgv', $source);
        $this->assertStringContainsString('PregCallbackInvokeJitHelper', $source);
        $this->assertStringContainsString('PregJitHelperThinAot', $source);
        $this->assertStringContainsString('replaceFindNext', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('thinMatchAllPart', $source);
        $this->assertStringContainsString('emitThinMatchAllHashtableFromParts', $source);
        $this->assertStringContainsString('implementThinSplitBridge', $source);
        $this->assertStringContainsString('emitThinSplitSubjectSlice', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('StringFormat::ensureLinked', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertMatchesRegularExpression(
            '/ensureCompiledBundle\(\s*\$context,\s*\$bundle,\s*self::COMPILED_HELPERS,\s*\'#24943\',\s*\$thin\s*\)/',
            $source
        );
        $link = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitVmHelperLink.php');
        $this->assertStringContainsString('skipHelperRuntimeCache', $link);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('pcre2_compile', $source);
        $this->assertStringNotContainsString('StringPregMatchStandaloneLlvm', $source);
        $this->assertStringNotContainsString('PregMatchUserScriptLlvm', $source);
        $this->assertStringNotContainsString('JitPregMatchKernel', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringPregMatchStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/PregMatchUserScriptLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitPregMatchKernel.php');
    }

    public function testPregJitHelperDelegatesToVmPregNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PregJitHelper.php');
        $this->assertStringContainsString('VmPregNative::pregMatch', $source);
        $this->assertStringContainsString('VmPregNative::pregReplace', $source);
        $this->assertStringContainsString('VmPregNative::pregReplaceCallbackByFnAddr', $source);
        $this->assertStringContainsString('VmPregMatches::hostMatchesToHashTable', $source);
    }

    public function testPregJitHelperMatchArgvSemantics(): void
    {
        $this->assertSame(1, PregJitHelper::matchArgv('/(\d+)/', 'abc123'));
        $this->assertSame(0, PregJitHelper::lastError());
        $this->assertSame(0, PregJitHelper::matchArgv('/z/', 'abc'));
        $this->assertSame(-1, PregJitHelper::matchArgv('[', 'abc'));
        $this->assertSame(1, PregJitHelper::lastError());
    }

    public function testSpineBundleIncludesPregPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PregJitHelper.php', $spine);
        $this->assertStringContainsString('PregMatchRuntime.php', $spine);
        $this->assertStringNotContainsString('JitPregMatchKernel.php', $spine);
        $this->assertStringNotContainsString('PregMatchUserScriptLlvm.php', $spine);
    }
}
