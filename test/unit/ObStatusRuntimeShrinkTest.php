<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ObStatusJitHelper;
use PHPCompiler\ext\standard\VmOb;
use PHPUnit\Framework\TestCase;

/** ObStatusRuntime must route through ObStatusJitHelper PHP, not LLVM hashtable builder (#9497). */
final class ObStatusRuntimeShrinkTest extends TestCase
{
    public function testObStatusRuntimeUsesObStatusJitHelperNotLlvmHashtableBuilder(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObStatusRuntime.php');
        $this->assertStringContainsString('buildStatusEntryPartial', $source);
        $this->assertStringNotContainsString('__phpc_ob_status_entry', $source);
        $this->assertStringNotContainsString('implementStatusEntry', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
    }

    public function testVmObDelegatesToObStatusJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmOb.php');
        $this->assertStringContainsString('ObStatusJitHelper::buildStatusEntryPartial', $source);
    }

    public function testObStatusJitHelperBuildsExpectedPartialStatus(): void
    {
        $ht = ObStatusJitHelper::buildStatusEntryPartial(0, 3);
        $used = $ht->find('buffer_used');
        $this->assertNotNull($used);
        $this->assertSame(3, $used->toInt());
        $this->assertNull($ht->find('name'));
    }
}
