<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** PendingHeaders routes through PendingHeadersJitHelper PHP (#9545, #13679). */
final class PendingHeadersRuntimeShrinkTest extends TestCase
{
    public function testPendingHeadersRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersRuntime.php');
        $this->assertStringContainsString('PendingHeadersJitBridge::implement', $runtime);
        $this->assertStringNotContainsString('PendingHeadersStandaloneLlvm', $runtime);
        $this->assertLessThan(35, substr_count($runtime, "\n"), 'PendingHeadersRuntime should be a thin router');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/PendingHeadersStandaloneLlvm.php');
    }
}
