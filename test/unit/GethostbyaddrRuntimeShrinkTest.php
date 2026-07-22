<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GethostbyaddrJitHelper;
use PHPCompiler\ext\standard\VmDns;
use PHPUnit\Framework\TestCase;

/**
 * GethostbyaddrRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#22370 / peer #22355).
 * Must route through GethostbyaddrJitHelper PHP, not glibc LLVM (#9474).
 */
final class GethostbyaddrRuntimeShrinkTest extends TestCase
{
    public function testGethostbyaddrRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GethostbyaddrRuntime.php');
        $this->assertStringContainsString('GethostbyaddrJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('GethostbyaddrLibcBridge', $source);
        $this->assertStringNotContainsString("lookupFunction('gethostbyaddr')", $source);
        $this->assertStringNotContainsString("lookupFunction('inet_pton')", $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertLessThan(150, substr_count($source, "\n") + 1);
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

    public function testIpv4ToInAddrArpaBuildsReverseZone(): void
    {
        $this->assertSame('8.8.8.8.in-addr.arpa', VmDns::ipv4ToInAddrArpa('8.8.8.8'));
        $this->assertNull(VmDns::ipv4ToInAddrArpa('not-an-ip'));
    }

    /** @group network */
    public function testGethostbyaddrResolvesPublicPtrWhenNetworkAvailable(): void
    {
        $error = VmDns::ERR_NONE;
        $result = VmDns::gethostbyaddr('8.8.8.8', $error);
        if (false === $result || '8.8.8.8' === $result) {
            $this->markTestSkipped('PTR lookup for 8.8.8.8 unavailable in this environment');
        }
        $this->assertIsString($result);
        $this->assertNotSame('8.8.8.8', $result);
        $this->assertStringContainsString('.', $result);
    }
}
