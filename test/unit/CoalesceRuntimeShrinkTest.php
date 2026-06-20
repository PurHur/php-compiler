<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Coalesce JIT routes value-box branch tests through CoalesceJitHelper PHP (#10171, #10311). */
final class CoalesceRuntimeShrinkTest extends TestCase
{
    public function testCoalesceHelperRoutesValueBoxThroughCoalesceJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/CoalesceHelper.php');
        $this->assertStringContainsString('CoalesceJitHelper', $source);
        $this->assertStringContainsString('takeLeftBranchFromTypeByte', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('CoalesceRuntime', $source);
    }

    public function testCoalesceRuntimeBridgeRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/CoalesceRuntime.php');
    }

    public function testCoalesceJitHelperMatchesVmCoalesceSemantics(): void
    {
        $helper = \PHPCompiler\VM\CoalesceJitHelper::class;
        $this->assertFalse($helper::takeLeftBranchFromTypeByte(\PHPCompiler\VM\Variable::TYPE_UNDEFINED));
        $this->assertFalse($helper::takeLeftBranchFromTypeByte(\PHPCompiler\VM\Variable::TYPE_NULL));
        $this->assertTrue($helper::takeLeftBranchFromTypeByte(\PHPCompiler\VM\Variable::TYPE_BOOLEAN));
        $this->assertTrue($helper::takeLeftBranchFromTypeByte(\PHPCompiler\VM\Variable::TYPE_INTEGER));
        $this->assertTrue($helper::takeLeftBranchFromTypeByte(\PHPCompiler\VM\Variable::TYPE_STRING));
    }
}
