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

    /** Invalid from() raises catchable throw-pending ValueError, not immediate abort (#24219). */
    public function testBackedEnumFromValueErrorUsesThrowPendingNotAbort(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/BackedEnumFromRuntime.php');
        $this->assertStringContainsString('phpc_jit_set_throw_pending', $source);
        $this->assertStringContainsString('raiseValueErrorFromString', $source);
        $raisePos = strpos($source, 'function raiseValueErrorFromString');
        $this->assertNotFalse($raisePos);
        $raiseBody = substr($source, $raisePos, 1200);
        $this->assertStringNotContainsString('phpc_jit_abort_if_pending_type_error', $raiseBody);
    }
}
