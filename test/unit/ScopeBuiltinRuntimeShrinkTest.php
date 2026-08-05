<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ScopeBuiltinHelper php-in-PHP shrink (#10184, NestedJIT→JitVmHelperLink #23261). */
final class ScopeBuiltinRuntimeShrinkTest extends TestCase
{
    private const HELPER_BASELINE_LOC = 1218;

    public function testScopeBuiltinHelperDelegatesToEmitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ScopeBuiltinHelper.php');
        $this->assertStringContainsString('ScopeBuiltinEmitHelper::walkStringKeyNodes', $source);
        $this->assertStringNotContainsString('function walkStringKeyNodes', $source);
        $this->assertStringNotContainsString('snprintf', $source);
    }

    public function testScopeBuiltinEmitHelperUsesPhpWarningBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ScopeBuiltinEmitHelper.php');
        $this->assertStringContainsString('ScopeBuiltinRuntime::emitCompactUndefinedVariableWarning', $source);
        $this->assertStringContainsString('ScopeBuiltinRuntime::emitCompactInvalidArgumentWarning', $source);
        $this->assertStringContainsString('ScopeBuiltinRuntime::resolveExtractTargetName', $source);
        $this->assertStringContainsString('ScopeBuiltinRuntime::collectCompactNamesFromHashtable', $source);
        $this->assertStringContainsString('ScopeBuiltinRuntime::storeVarSnapshotAtStringKey', $source);
        $this->assertStringContainsString('ScopeBuiltinRuntime::matchNamedVariableIndex', $source);
        $this->assertStringContainsString('ScopeBuiltinRuntime::matchNamedVariableIndexFromCstr', $source);
        $this->assertStringNotContainsString('snprintf', $source);
        $this->assertStringNotContainsString('emitCompactInvalidArgumentWarningMessage', $source);
        $this->assertStringNotContainsString('importKeyIntoScope', $source);
        $this->assertStringNotContainsString('storeDefinedVarAtStringKey', $source);
        $this->assertStringNotContainsString('collectCompactFromHashtable', $source);
        $this->assertStringNotContainsString("lookupFunction('strcmp')", $source);
    }

    public function testScopeBuiltinRuntimeLinksMatchNamedVariableIndex(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ScopeBuiltinRuntime.php');
        $this->assertStringContainsString('matchNamedVariableIndex', $source);
        $this->assertStringContainsString('__scope_match_named_var_index', $source);
        $this->assertStringContainsString('ScopeBuiltinJitHelper::matchNamedVariableIndex', $source);
    }

    public function testScopeBuiltinRuntimeStandaloneWarningsUsePhpBridges(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ScopeBuiltinRuntime.php');
        $this->assertStringContainsString('__scope_compact_invalid_arg_warn', $source);
        $this->assertStringContainsString('__scope_compact_undef_warn', $source);
        $this->assertStringContainsString('ensureCompactInvalidArgWarnStandaloneLinked', $source);
        $this->assertStringContainsString('ensureCompactUndefWarnStandaloneLinked', $source);
        $this->assertStringNotContainsString("lookupFunction('snprintf')", $source);
        $this->assertStringNotContainsString('compact_invalid_int_', $source);
        $this->assertStringNotContainsString('emitStandaloneCompactInvalidArgumentWarningMessage', $source);
        $loc = substr_count($source, "\n") + 1;
        $this->assertLessThan(520, $loc, 'ScopeBuiltinRuntime.php LOC');
    }

    public function testScopeBuiltinRuntimeHelperCompileViaJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ScopeBuiltinRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testScopeBuiltinJitHelperMatchNamedVariableIndex(): void
    {
        $table = "foo\0bar";
        $this->assertSame(0, \PHPCompiler\ext\standard\ScopeBuiltinJitHelper::matchNamedVariableIndex('foo', $table));
        $this->assertSame(1, \PHPCompiler\ext\standard\ScopeBuiltinJitHelper::matchNamedVariableIndex('BAR', $table));
        $this->assertSame(-1, \PHPCompiler\ext\standard\ScopeBuiltinJitHelper::matchNamedVariableIndex('missing', $table));
        // NestedJIT cannot lower array_filter()+callback when compiling this helper (#27520).
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ScopeBuiltinJitHelper.php');
        $this->assertStringNotContainsString('\\array_filter(', $source);
        $this->assertTrue(\PHPCompiler\ext\standard\ScopeBuiltinJitHelper::isValidVarName('hello'));
        $this->assertTrue(\PHPCompiler\ext\standard\ScopeBuiltinJitHelper::isValidVarName('_n7'));
        $this->assertFalse(\PHPCompiler\ext\standard\ScopeBuiltinJitHelper::isValidVarName('7bad'));
        $this->assertFalse(\PHPCompiler\ext\standard\ScopeBuiltinJitHelper::isValidVarName('a-b'));
        $this->assertFalse(\PHPCompiler\ext\standard\ScopeBuiltinJitHelper::isValidVarName(''));
    }

    public function testScopeBuiltinJitHelperCompactAndDefinedVarsBridges(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ScopeBuiltinJitHelper.php');
        $this->assertStringContainsString('collectCompactNamesFromHashtable', $source);
        $this->assertStringContainsString('storeVarSnapshotAtStringKey', $source);
        $this->assertStringContainsString('emitCompactInvalidArgumentWarningFromVariable', $source);
    }

    public function testScopeBuiltinJitHelperExtractNameResolution(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ScopeBuiltinJitHelper.php');
        $this->assertStringContainsString('resolveExtractFinalName', $source);
        $this->assertStringContainsString('resolveExtractTargetName', $source);
        $this->assertStringContainsString('prefixVarName', $source);
    }

    public function testScopeBuiltinJitHelperExists(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ScopeBuiltinJitHelper.php');
        $this->assertStringContainsString('compiler_language_warning', $source);
        $this->assertStringContainsString('compact(): Undefined variable', $source);
        $this->assertStringContainsString('compactInvalidArgumentMessage', $source);
        $this->assertStringContainsString('emitCompactInvalidArgumentWarning', $source);
    }

    public function testScopeBuiltinHelperShrunkAtLeastFiftyPercent(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/ScopeBuiltinHelper.php'), "\n") + 1;
        $this->assertLessThanOrEqual(
            (int) floor(self::HELPER_BASELINE_LOC * 0.5),
            $loc,
            'ScopeBuiltinHelper.php LOC'
        );
    }

    public function testScopeBuiltinEmitHelperShrunkBelowPhase2Baseline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ScopeBuiltinEmitHelper.php');
        $loc = substr_count($source, "\n") + 1;
        // Phase-4b baseline was ≤550 (#19043); #22136 ~574; #27520 EXTR_OVERWRITE AOT path ≤660.
        $this->assertLessThan(660, $loc, 'ScopeBuiltinEmitHelper.php LOC');
        $this->assertStringContainsString('HashTableReadLlvm::forEachStringKeyNode', $source);
        $this->assertStringContainsString('HashTableReadLlvm::forEachIndexedStringAt', $source);
        $this->assertStringContainsString('ScopeBuiltinDefinedLlvm::getDefinedVars', $source);
        $this->assertStringContainsString('ScopeBuiltinIndexLlvm::branchOnNamedVariableIndex', $source);
        $this->assertStringNotContainsString('scope_import_str_head', $source);
        $this->assertStringNotContainsString('compact_names_str_head', $source);
        $this->assertStringNotContainsString("lookupFunction('strcmp')", $source);
    }

    public function testScopeBuiltinJitHelperDefinedVarsSnapshot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ScopeBuiltinJitHelper.php');
        $this->assertStringContainsString('buildDefinedVarsSnapshot', $source);
        $this->assertStringContainsString('buildDeclaredVariablesSnapshot', $source);
        $this->assertStringContainsString('foreachNonEmptyStringKey', $source);
        $this->assertStringContainsString('addIndex', $source);
    }
}
