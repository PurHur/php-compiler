<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\VM\VariableFunctionCallJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * VariableFunctionCall dispatch (#10135 / #35075).
 */
final class VariableFunctionCallShrinkTest extends TestCase
{
    public function testVariableFunctionCallHelperUsesRuntimeNotLlvmStringCompare(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/VariableFunctionCallHelper.php');
        $this->assertStringContainsString('VariableFunctionCallRuntime::dispatch', $source);
        $this->assertStringNotContainsString('JitStringCompare::identical', $source);
        $this->assertStringNotContainsString('dispatchSingleCandidate', $source);
        $this->assertStringNotContainsString('boxCallResult', $source);
        // Helper was ~387 lines with inline JitStringCompare; foreach array-literal hint
        // tracing (#35075) grows it modestly — keep a budget against full re-bloat.
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(360, $lineCount);
        $this->assertGreaterThan(50, 387 - $lineCount);
    }

    public function testVariableFunctionCallRuntimeUsesNameCompareNotBrokenIndexHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/VariableFunctionCallRuntime.php');
        // #35075: NestedJIT index-table helper always selected the first callee.
        $this->assertStringContainsString('JitStringCompare::identical', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new \\PHPCompiler\\JIT(', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
    }

    public function testVariableFunctionCallJitHelperDelegatesToSharedSemantics(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/VM/VariableFunctionCallJitHelper.php');
        $this->assertStringContainsString('VariableFunctionCall::matchCandidateIndex', $source);
    }

    public function testMatchCandidateIndexSemantics(): void
    {
        $this->assertSame(0, VariableFunctionCall::matchCandidateIndex('strlen', ['strlen', 'myfn']));
        $this->assertSame(1, VariableFunctionCall::matchCandidateIndex('MyFn', ['strlen', 'myfn']));
        $this->assertSame(-1, VariableFunctionCall::matchCandidateIndex('missing', ['strlen']));
        $table = "strlen\x1emyfn";
        $this->assertSame(1, VariableFunctionCallJitHelper::matchCandidateIndex('myfn', $table));
        $this->assertSame(0, VariableFunctionCallJitHelper::matchCandidateIndex('strlen', $table));
    }
}
