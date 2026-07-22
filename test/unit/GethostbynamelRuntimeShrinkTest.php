<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GethostbynamelRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#22397 / peer #22370).
 * Must route through GethostbynamelJitHelper PHP, not glibc addrinfo LLVM (#9382).
 */
final class GethostbynamelRuntimeShrinkTest extends TestCase
{
    public function testGethostbynamelRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GethostbynamelRuntime.php');
        $this->assertStringContainsString('GethostbynamelJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\BasicBlockHelper;', $source);
        $this->assertStringNotContainsString('getaddrinfo', $source);
        $this->assertStringNotContainsString('freeaddrinfo', $source);
        $this->assertStringNotContainsString('inet_ntop', $source);
        $this->assertStringNotContainsString('ADDRINFO_', $source);
        $this->assertStringContainsString('__hashtable__setStringAt', $source);
        $this->assertLessThan(200, substr_count($source, "\n") + 1);
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
