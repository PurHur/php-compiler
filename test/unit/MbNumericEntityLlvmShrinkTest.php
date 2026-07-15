<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** User-script mb numeric entity routes through JitVmHelperLink + MbNumericEntityJitHelper PHP (#19094). */
final class MbNumericEntityLlvmShrinkTest extends TestCase
{
    public function testMbNumericEntityLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/MbNumericEntityLlvm.php');
    }

    public function testMbNumericEntityUsesJitVmHelperLinkForAllPaths(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbNumericEntity.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringContainsString('MbNumericEntityJitHelper', $runtime);
        $this->assertStringNotContainsString('MbNumericEntityLlvm', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
    }
}
