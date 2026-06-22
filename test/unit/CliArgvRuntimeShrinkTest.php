<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** CliArgvRuntime must route hashtable materialization through CliArgvJitHelper PHP (#9439). */
final class CliArgvRuntimeShrinkTest extends TestCase
{
    public function testCliArgvRuntimeUsesJitHelperNotLlvmHashtableLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CliArgvRuntime.php');
        $this->assertStringContainsString('CliArgvJitHelper', $source);
        $this->assertStringContainsString('VmCliArgv', $source);
        $this->assertStringNotContainsString('__hashtable__alloc', $source);
        $this->assertStringContainsString('CREATE_TABLE_HELPER', $source);
    }

    public function testVmCliArgvBuildsIndexedArgvTable(): void
    {
        $ht = \PHPCompiler\ext\standard\VmCliArgv::buildArgvTable(['script.php', 'foo']);
        $this->assertSame(2, $ht->getNumElements());
        $this->assertSame('script.php', $ht->findIndex(0)?->toString());
        $this->assertSame('foo', $ht->findIndex(1)?->toString());
    }

    public function testSuperglobalsPopulateCliArgvUsesVmCliArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Web/Superglobals.php');
        $this->assertStringContainsString('VmCliArgv::buildArgvTable', $source);
    }
}
