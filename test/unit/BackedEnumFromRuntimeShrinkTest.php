<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** BackedEnumFromJit routes coercion through BackedEnumFromRuntime LLVM lowering (#10273, #24208). */
final class BackedEnumFromRuntimeShrinkTest extends TestCase
{
    public function testBackedEnumFromJitUsesBackedEnumFromRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/BackedEnumFromJit.php');
        $this->assertStringContainsString('BackedEnumFromRuntime', $source);
        $this->assertStringContainsString('JitStringCompare::identical', $source);
        $this->assertStringNotContainsString('normalizeValueBoxToString', $source);
        $this->assertStringNotContainsString('emitDynamicValueError', $source);
        $this->assertLessThan(280, substr_count($source, "\n") + 1);
    }

    public function testBackedEnumFromRuntimeAvoidsNestedVmHelperForMatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/BackedEnumFromRuntime.php');
        $this->assertStringContainsString('EnumFromJitHelper', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('matchStringBackingPacked', $source);
    }
}
