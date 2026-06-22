<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** GethostbynamelRuntime must route through GethostbynamelJitHelper PHP, not glibc addrinfo LLVM (#9382). */
final class GethostbynamelRuntimeShrinkTest extends TestCase
{
    public function testGethostbynamelRuntimeUsesJitHelperNotGlibcLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GethostbynamelRuntime.php');
        $this->assertStringContainsString('GethostbynamelJitHelper', $source);
        $this->assertStringNotContainsString('getaddrinfo', $source);
        $this->assertStringNotContainsString('freeaddrinfo', $source);
        $this->assertStringNotContainsString('inet_ntop', $source);
        $this->assertStringNotContainsString('ADDRINFO_', $source);
        $this->assertStringContainsString('__hashtable__setStringAt', $source);
        $this->assertLessThan(260, substr_count($source, "\n"));
    }

    public function testGethostbynamelJitHelperDelegatesToVmDns(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GethostbynamelJitHelper.php');
        $this->assertStringContainsString('VmDns::resolveHostnameIpv4List', $source);
        $this->assertStringContainsString('ipCount', $source);
        $this->assertStringContainsString('ipAt', $source);
    }

    public function testJitGethostbynamelUsesRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGethostbynamel.php');
        $this->assertStringContainsString('GethostbynamelRuntime::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_gethostbynamel', $source);
    }
}
