<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** UndefinedVariableRuntime routes warnings through UndefinedVariableJitHelper PHP (#10360). */
final class UndefinedVariableRuntimeShrinkTest extends TestCase
{
    public function testUndefinedVariableRuntimeUsesCompiledJitHelperNotInlineMessageTable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UndefinedVariableRuntime.php');
        $this->assertStringContainsString('UndefinedVariableJitHelper', $source);
        $this->assertStringContainsString('emitWarning', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }

    public function testUndefinedVariableJitHelperDefinesEmitWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/UndefinedVariableJitHelper.php');
        $this->assertStringContainsString('emitWarning', $source);
        $this->assertStringContainsString('compiler_language_warning', $source);
        $this->assertStringContainsString('Undefined variable', $source);
    }

    public function testUndefinedVariableHelperGuardsScopeReads(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/UndefinedVariableHelper.php');
        $this->assertStringContainsString('guardBeforeRuntimeRead', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('markAssigned', $source);
        $this->assertStringContainsString('ScopeVariableAssignedFlags', $source);
    }
}
