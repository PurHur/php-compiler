<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LcgJitHelper;
use PHPCompiler\ext\standard\VmCombinedLcg;
use PHPUnit\Framework\TestCase;

/**
 * Lcg NestedJIT via JitVmHelperLink::ensureCompiled (#22495 / peer #22468).
 */
final class LcgRuntimeShrinkTest extends TestCase
{
    public function testLcgRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Lcg.php');
        $this->assertStringContainsString('LcgJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testLcgJitHelperDelegatesToVmCombinedLcg(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LcgJitHelper.php');
        $this->assertStringContainsString('VmCombinedLcg::value', $source);
    }

    public function testLcgJitHelperMatchesVmCombinedLcg(): void
    {
        VmCombinedLcg::resetForTests();
        VmCombinedLcg::seed(12345, 67890);
        $expected = VmCombinedLcg::value();

        VmCombinedLcg::resetForTests();
        VmCombinedLcg::seed(12345, 67890);
        $actual = LcgJitHelper::value();

        $this->assertEqualsWithDelta($expected, $actual, 1e-14);
    }
}
