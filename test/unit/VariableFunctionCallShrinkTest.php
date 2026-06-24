<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VariableFunctionCall;
use PHPCompiler\VM\VariableFunctionCallJitHelper;
use PHPUnit\Framework\TestCase;

/** VariableFunctionCall JIT routes name matching through PHP helper (#10135). */
final class VariableFunctionCallShrinkTest extends TestCase
{
    public function testVariableFunctionCallHelperUsesRuntimeNotLlvmStringCompare(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/VariableFunctionCallHelper.php');
        $this->assertStringContainsString('VariableFunctionCallRuntime::dispatch', $source);
        $this->assertStringNotContainsString('JitStringCompare::identical', $source);
        $this->assertStringNotContainsString('dispatchSingleCandidate', $source);
        $this->assertStringNotContainsString('boxCallResult', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(230, $lineCount);
        $this->assertGreaterThan(150, 387 - $lineCount);
    }

    public function testVariableFunctionCallRuntimeUsesJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/VariableFunctionCallRuntime.php');
        $this->assertStringContainsString('VariableFunctionCallJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('JitStringCompare::identical', $source);
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
        $table = "strlen\0myfn";
        $this->assertSame(1, VariableFunctionCallJitHelper::matchCandidateIndex('myfn', $table));
    }
}
