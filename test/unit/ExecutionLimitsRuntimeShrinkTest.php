<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExecutionLimitsJitHelper;
use PHPUnit\Framework\TestCase;

/** ExecutionLimitsRuntime routes through ExecutionLimitsJitHelper PHP not LLVM globals (#9339). */
final class ExecutionLimitsRuntimeShrinkTest extends TestCase
{
    public function testExecutionLimitsRuntimeUsesCompiledJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ExecutionLimitsRuntime.php');
        $this->assertStringContainsString('ExecutionLimitsJitHelper', $source);
        $this->assertStringContainsString('ensureJitHelperCompiled', $source);
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
