<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ScopeBuiltinHelper php-in-PHP shrink (#10184). */
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
        $this->assertStringNotContainsString('snprintf', $source);
        $this->assertStringNotContainsString('emitCompactInvalidArgumentWarningMessage', $source);
        $this->assertStringNotContainsString('importKeyIntoScope', $source);
        $this->assertStringNotContainsString('storeDefinedVarAtStringKey', $source);
        $this->assertStringNotContainsString('collectCompactFromHashtable', $source);
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
}
