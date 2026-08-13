<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ProcessIdentityJit: getmyuid/gid via ensureCompiled; getmypid via ensureBridge (#21259, #30623).
 */
final class ProcessIdentityRuntimeShrinkTest extends TestCase
{
    public function testProcessIdentityJitUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessIdentityJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('ProcessIdentityJitHelper', $source);
        $this->assertStringContainsString('GetmypidJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString("lookupFunction('getpid')", $source);
    }

    public function testJitDateStillRoutesThroughProcessIdentityJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('ProcessIdentityJit::getmypid', $source);
        $this->assertStringContainsString('ProcessIdentityJit::getmyuid', $source);
        $this->assertStringContainsString('ProcessIdentityJit::getmygid', $source);
        $this->assertStringNotContainsString("lookupFunction('getpid')", $source);
    }

    public function testProcessIdentityJitHelperDelegatesToVm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ProcessIdentityJitHelper.php');
        $this->assertStringContainsString('VmDate::getmypid()', $source);
        $this->assertStringContainsString('VmProcessIdentity::getmyuid()', $source);
        $this->assertStringContainsString('VmProcessIdentity::getmygid()', $source);
        $this->assertStringContainsString('VmProcessIdentity::getCurrentUserForScript', $source);
    }

    public function testGetCurrentUserUsesLibcNotNestedHelper(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessIdentityJit.php');
        $this->assertStringContainsString('JitGetCurrentUser::invoke', $jit);
        $this->assertStringNotContainsString('resolveGetCurrentUser', $jit);
        $emit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetCurrentUser.php');
        $this->assertStringContainsString("lookupFunction('geteuid')", $emit);
        $this->assertStringContainsString("lookupFunction('getpwuid')", $emit);
    }
}
