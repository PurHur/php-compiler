<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Bootstrap M3 emit uses JitStringSearch::findOffsetI32 backed by FindSubstrJitHelper PHP (#15287). */
final class BootstrapCompileSmokeM3EmitShrinkTest extends TestCase
{
    public function testBootstrapEmitUsesFindSubstrPhpBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
    }

    public function testBootstrapEmitDeclaresPutenvModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('ensureLibcPutenv', $source);
        $this->assertStringContainsString('#31582', $source);
        $this->assertStringContainsString("lookupFunction('putenv')", $source);
        $this->assertStringContainsString("addFunction(", $source);
        $this->assertMatchesRegularExpression("/addFunction\\(\\s*'putenv'/", $source);
    }

    public function testModuleJitInitDoesNotRegisterStrstr(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("addFunction('strstr'", $source);
    }
}
