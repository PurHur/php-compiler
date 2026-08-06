<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ObStatusJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * ObStatusRuntime routes through ObStatusJitHelper via JitVmHelperLink::ensureCompiled
 * (#9497 / #27321 / peer #27037).
 */
final class ObStatusRuntimeShrinkTest extends TestCase
{
    public function testObStatusRuntimeUsesObStatusJitHelperNotLlvmHashtableBuilder(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObStatusRuntime.php');
        $this->assertStringContainsString('buildStatusEntryDefault', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('__phpc_ob_status_entry', $source);
        $this->assertStringNotContainsString('implementStatusEntry', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('putenv', $source);
    }

    public function testVmObDelegatesToObStatusJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmOb.php');
        $this->assertStringContainsString('ObStatusJitHelper::buildStatusEntry', $source);
    }

    public function testObStatusJitHelperBuildsExpectedStatusKeyOrder(): void
    {
        $ht = ObStatusJitHelper::buildStatusEntry(0, 3, ObStatusJitHelper::HANDLER_NAME);
        $keys = [];
        foreach ($ht->iterateKeyed(true) as [$keyVar, $_]) {
            $keys[] = $keyVar->resolveIndirect()->toString();
        }
        $this->assertSame(
            ['name', 'type', 'flags', 'level', 'chunk_size', 'buffer_size', 'buffer_used'],
            $keys
        );
        $used = $ht->find('buffer_used');
        $this->assertNotNull($used);
        $this->assertSame(3, $used->toInt());
        $name = $ht->find('name');
        $this->assertNotNull($name);
        $this->assertSame(ObStatusJitHelper::HANDLER_NAME, $name->toString());
    }

    public function testSpineBundleIncludesObStatusJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ObStatusJitHelper.php', $spine);
        $this->assertStringContainsString('ObStatusRuntime.php', $spine);
    }
}
