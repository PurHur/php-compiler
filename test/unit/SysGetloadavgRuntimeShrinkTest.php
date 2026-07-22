<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SysGetloadavgRuntime NestedJIT via JitVmHelperLink::ensureCompiled (#22399 / peer #22370).
 * Must route through SysGetloadavgJitHelper PHP, not libc getloadavg LLVM (#12106).
 */
final class SysGetloadavgRuntimeShrinkTest extends TestCase
{
    public function testSysGetloadavgRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetloadavgRuntime.php');
        $this->assertStringContainsString('SysGetloadavgJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString("lookupFunction('getloadavg')", $source);
        $this->assertLessThan(180, substr_count($source, "\n") + 1);
    }

    public function testSysGetloadavgJitHelperDelegatesToVmSys(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SysGetloadavgJitHelper.php');
        $this->assertStringContainsString('VmSys::getLoadavg', $source);
        $this->assertStringContainsString('resolve', $source);
    }

    public function testJitSysGetloadavgUsesRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSysGetloadavg.php');
        $this->assertStringContainsString('StringSysGetloadavg::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_sys_getloadavg', $source);
    }
}
