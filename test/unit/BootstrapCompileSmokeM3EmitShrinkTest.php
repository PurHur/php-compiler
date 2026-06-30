<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Bootstrap M3 emit selfhost path probe uses JitStringSearch, not libc strstr (#14080). */
final class BootstrapCompileSmokeM3EmitShrinkTest extends TestCase
{
    public function testEmitPutenvProbeUsesJitStringSearchNotLibcStrstr(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('JitStringSearch::findOffsetI32', $source);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $source);
    }

    public function testModuleJitInitDoesNotRegisterStrstr(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("addFunction('strstr'", $source);
    }
}
