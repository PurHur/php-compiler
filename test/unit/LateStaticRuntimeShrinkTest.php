<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * LateStaticBindingRuntime routes through LateStaticJitHelper via JitVmHelperLink::ensureCompiled
 * (#10247 / #27416 / peer #27321).
 */
final class LateStaticRuntimeShrinkTest extends TestCase
{
    public function testLateStaticBindingRuntimeUsesJitHelperNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LateStaticBindingRuntime.php');
        $this->assertStringContainsString('LateStaticJitHelper', $source);
        $this->assertStringContainsString('effectiveCalledClassId', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
    }

    public function testLateStaticBindingHelperDelegatesToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LateStaticBindingHelper.php');
        $this->assertStringContainsString('LateStaticBindingRuntime', $source);
        $this->assertStringNotContainsString('phpc_late_static_class_id', $source);
        $this->assertLessThanOrEqual(120, substr_count($source, "\n") + 1);
    }

    public function testLateStaticJitHelperAlignsWithVmSsot(): void
    {
        $this->assertSame(5, \PHPCompiler\VM\LateStaticJitHelper::effectiveCalledClassId(5, 3));
        $this->assertSame(3, \PHPCompiler\VM\LateStaticJitHelper::effectiveCalledClassId(0, 3));
        $this->assertSame('child', \PHPCompiler\VM\LateStaticBinding::resolveLateStaticClassLc('Child', 'Base'));
        $this->assertSame('base', \PHPCompiler\VM\LateStaticBinding::resolveLateStaticClassLc(null, 'Base'));
    }
}
