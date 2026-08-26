<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\VM\VariableFunctionCallJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * VariableFunctionCall JIT routes name matching through PHP helper (#10135).
 * NestedJIT via JitVmHelperLink::ensureCompiled (#24902 / peer #22519).
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
        // Helper was ~387 lines with inline JitStringCompare; keep a budget against re-bloat (#10135).
        // Foreach INIT_ARRAY hint scan (#35075) adds ~60 lines.
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(360, $lineCount);
        $this->assertGreaterThan(200, $lineCount);
    }

    public function testVariableFunctionCallRuntimeUsesJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/VariableFunctionCallRuntime.php');
        $this->assertStringContainsString('JitStringCompare::strcmp', $source);
        $this->assertStringContainsString('matchIndexByStrcmp', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new \\PHPCompiler\\JIT(', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('implode("\\0"', $source);
        $this->assertStringNotContainsString("implode(\"\\0\"", $source);
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
    }
}
