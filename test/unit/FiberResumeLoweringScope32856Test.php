<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fiber resume lowering must own loweringLlvmFunction (#32856).
 *
 * Without it, value_copy / ret i64 spill into void @internal_N (Module.php:180).
 * Discarded Fiber::suspend() must not treat FUNCCALL_EXEC_NORETURN arg1 as a result temp.
 */
final class FiberResumeLoweringScope32856Test extends TestCase
{
    public function testCompileResumeFunctionScopesLoweringLlvmFunction(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/FiberHelperLlvm.php');
        $this->assertStringContainsString('#32856', $src);
        $this->assertStringContainsString('loweringLlvmFunction = $func instanceof', $src);
        $this->assertStringContainsString('savedLowering', $src);
        $this->assertDoesNotMatchRegularExpression(
            '/TYPE_FUNCCALL_EXEC_NORETURN === \$op->type \|\| OpCode::TYPE_FUNCCALL_EXEC_RETURN/',
            $src,
            'NORETURN must not share resultOp binding with EXEC_RETURN (#32856)'
        );
        $this->assertStringContainsString(
            'TYPE_FUNCCALL_EXEC_RETURN === $op->type',
            $src
        );
    }

    public function testReproFixtureExists(): void
    {
        $this->assertFileExists(
            __DIR__.'/../repro/issue_32856_fiber_void_suspend_aot.php'
        );
    }
}
