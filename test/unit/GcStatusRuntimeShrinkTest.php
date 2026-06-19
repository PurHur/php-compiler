<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** GcStatusRuntime must route through GcStatusJitHelper PHP, not LLVM hashtable assembly (#9150). */
final class GcStatusRuntimeShrinkTest extends TestCase
{
    public function testGcStatusRuntimeUsesGcStatusJitHelperNotHashtableLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcStatusRuntime.php');
        $this->assertStringContainsString('GcStatusJitHelper', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyBool', $source);
        $this->assertStringNotContainsString('implementStatusHt', $source);
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
