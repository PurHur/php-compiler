<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StatArrayJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** stat()/lstat() JIT routes through StatArrayJitHelper PHP not StringFsDirJit LLVM (#9585). */
final class StatArrayRuntimeShrinkTest extends TestCase
{
    public function testStatArrayJitHelperDelegatesToVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StatArrayJitHelper.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertStringContainsString('VmStatCache::lstat', $source);
    }

    public function testStringFsDirJitDelegatesStatToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('StatArrayRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitStat', $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertStringNotContainsString("lookupFunction('lstat')", $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StatArrayLlvm.php');
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StatArrayRuntime.php');
        $this->assertStringNotContainsString('StatArrayLlvm', $runtime);
    }

    public function testStatArrayRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StatArrayRuntime.php');
        $this->assertStringContainsString('StatArrayJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testStatArrayJitHelperMatchesVmFs(): void
    {
        $path = __FILE__;
        $expected = VmFs::statInfo($path, false);
        $this->assertNotFalse($expected);
        $actual = StatArrayJitHelper::statArgv($path, 0);
        $this->assertNotNull($actual);
        $expSize = $expected->findIndex(7);
        $actSize = $actual->findIndex(7);
        $this->assertNotNull($expSize);
        $this->assertNotNull($actSize);
        $this->assertSame($expSize->toInt(), $actSize->toInt());
        $this->assertNull(StatArrayJitHelper::statArgv('/nonexistent/phpc_stat_array_xyz', 0));
    }
}
