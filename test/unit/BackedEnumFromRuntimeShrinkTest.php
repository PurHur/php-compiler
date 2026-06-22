<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** BackedEnumFromJit must route through EnumFromJitHelper PHP, not monolithic LLVM (#10273). */
final class BackedEnumFromRuntimeShrinkTest extends TestCase
{
    public function testBackedEnumFromJitUsesBackedEnumFromRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/BackedEnumFromJit.php');
        $this->assertStringContainsString('BackedEnumFromRuntime', $source);
        $this->assertStringNotContainsString('normalizeValueBoxToString', $source);
        $this->assertStringNotContainsString('emitDynamicValueError', $source);
        $this->assertLessThan(280, substr_count($source, "\n") + 1);
    }

    public function testBackedEnumFromRuntimeUsesEnumFromJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/BackedEnumFromRuntime.php');
        $this->assertStringContainsString('EnumFromJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
    }
}
