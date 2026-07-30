<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExecutionLimitsJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * ExecutionLimitsRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#25269 / peer #25252).
 */
final class ExecutionLimitsRuntimeShrinkTest extends TestCase
{
    public function testExecutionLimitsRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ExecutionLimitsRuntime.php');
        $this->assertStringContainsString('ExecutionLimitsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('phpc_exec_limit_deadline', $source);
        $this->assertStringNotContainsString('phpc_exec_limit_seconds', $source);
        $this->assertStringNotContainsString('__compiler_microtime_float', $source);
        $this->assertLessThan(200, substr_count($source, "\n") + 1);
    }

    public function testExecutionLimitsJitHelperSemantics(): void
    {
        $this->assertTrue(ExecutionLimitsJitHelper::setTimeLimit(60));
        $this->assertSame(0, ExecutionLimitsJitHelper::ignoreUserAbort(0, 0));
        $this->assertSame(0, ExecutionLimitsJitHelper::ignoreUserAbort(1, 1));
        $this->assertSame(1, ExecutionLimitsJitHelper::ignoreUserAbort(1, 0));
        $this->assertSame(0, ExecutionLimitsJitHelper::connectionAborted());
    }
}
