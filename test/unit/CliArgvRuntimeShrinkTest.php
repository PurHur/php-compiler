<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** CliArgvRuntime LLVM uses __hashtable__alloc; VM argv SSOT is VmCliArgv (#9439, #11142). */
final class CliArgvRuntimeShrinkTest extends TestCase
{
    public function testCliArgvRuntimeUsesLlvmAllocNotNestedJitHelperCompile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CliArgvRuntime.php');
        $this->assertStringContainsString('__hashtable__alloc', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('CliArgvJitHelper::createTable', $source);
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
