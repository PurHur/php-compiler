<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards for #21972 — emit-helper link must not throw "Current basic block has no parent function".
 */
final class EmitHelperLinkInsertRestoreTest extends TestCase
{
    public function testSkippedCompilerSplitCfgStubCollectsDefaultsBeforeClearInsert(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertMatchesRegularExpression(
            '/function compileSkippedCompilerSplitCfgStub[\s\S]*?'
            .'\$defaultArgs = \$this->collectParamDefaults\(\$block\);[\s\S]*?'
            .'clearInsertionPosition\(\);/',
            $source
        );
    }

    public function testJitVmHelperLinkEnsureCompiledRestoresInsert(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitVmHelperLink.php');
        $this->assertMatchesRegularExpression(
            '/function ensureCompiled[\s\S]*BasicBlockHelper::restoreInsertBlock/',
            $source
        );
    }

    public function testNestedJitCompileScopeRestoresViaBasicBlockHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/NestedJitCompileScope.php');
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
    }
}
