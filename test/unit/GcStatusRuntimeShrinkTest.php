<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GcStatusRuntime AOT bridge materializes HT via LLVM ABI; VM keeps GcStatusJitHelper (#9150 / #26472 / #26943).
 */
final class GcStatusRuntimeShrinkTest extends TestCase
{
    public function testGcStatusRuntimeRestoresInsertAndUsesHashtableAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcStatusRuntime.php');
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertStringContainsString('__hashtable__setStringKeyBool', $source);
        $this->assertStringContainsString('__hashtable__alloc', $source);
        // NestedJIT of GcStatusJitHelper::buildTable AOT-SEGVs (#26943) — bridge must not call it.
        $this->assertStringNotContainsString('GcStatusJitHelper::', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testVmGcStatusDelegatesToGcStatusJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGcStatus.php');
        $this->assertStringContainsString('GcStatusJitHelper::buildTable', $source);
    }

    public function testJitGcStatusUsesGcStatusRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGcStatus.php');
        $this->assertStringContainsString('GcStatusRuntime', $source);
        $this->assertStringContainsString('__phpc_gc_status_ht', $source);
    }
}
