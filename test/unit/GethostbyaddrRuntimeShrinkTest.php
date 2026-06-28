<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GethostbyaddrJitHelper;
use PHPCompiler\ext\standard\VmDns;
use PHPUnit\Framework\TestCase;

/** GethostbyaddrRuntime must route through GethostbyaddrJitHelper PHP, not glibc LLVM (#9474). */
final class GethostbyaddrRuntimeShrinkTest extends TestCase
{
    public function testGethostbyaddrRuntimeUsesJitHelperNotGlibcLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GethostbyaddrRuntime.php');
        $this->assertStringContainsString('GethostbyaddrJitHelper', $source);
        $this->assertStringNotContainsString('GethostbyaddrLibcBridge', $source);
        $this->assertStringNotContainsString("lookupFunction('gethostbyaddr')", $source);
        $this->assertStringNotContainsString("lookupFunction('inet_pton')", $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertLessThan(200, substr_count($source, "\n"));
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/GethostbyaddrLibcBridge.php');
    }

    public function testGethostbyaddrJitHelperDelegatesToVmDns(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GethostbyaddrJitHelper.php');
        $this->assertStringContainsString('VmDns::gethostbyaddr', $source);
        $this->assertStringContainsString('resolve', $source);
    }

    public function testJitGethostbyaddrUsesRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGethostbyaddr.php');
        $this->assertStringContainsString('GethostbyaddrRuntime::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_gethostbyaddr', $source);
    }

    public function testGethostbyaddrJitHelperMatchesVmDnsOnLoopback(): void
    {
        if (false === VmDns::gethostbyaddr('127.0.0.1')) {
            $this->markTestSkipped('native gethostbyaddr(127.0.0.1) unavailable');
        }
        $expected = VmDns::gethostbyaddr('127.0.0.1');
        $this->assertIsString($expected);
        $this->assertSame($expected, GethostbyaddrJitHelper::resolve('127.0.0.1'));
        $this->assertSame('10.0.0.1', GethostbyaddrJitHelper::resolve('10.0.0.1'));
        $this->assertSame('', GethostbyaddrJitHelper::resolve('not-an-ip'));
    }
}
