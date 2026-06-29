<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** PendingHeaders embed uses JIT helper; standalone AOT keeps thin LLVM quarantine (#9545, #13571). */
final class PendingHeadersRuntimeShrinkTest extends TestCase
{
    public function testPendingHeadersRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersRuntime.php');
        $this->assertStringContainsString('PendingHeadersJitBridge::implement', $runtime);
        $this->assertStringContainsString('PendingHeadersStandaloneLlvm::implement', $runtime);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersStandaloneLlvm.php');
    }
}
