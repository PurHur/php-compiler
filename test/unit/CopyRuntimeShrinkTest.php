<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CopyJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/**
 * copy() JIT routes through CopyJitHelper PHP not StringFsDirJit libc LLVM (#9585).
 * NestedJIT via JitVmHelperLink::ensureCompiled (#22231 / peer #22205).
 */
final class CopyRuntimeShrinkTest extends TestCase
{
    public function testStringFsDirJitDelegatesCopyToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('CopyRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitCopy', $source);
        $this->assertStringNotContainsString("lookupFunction('fopen')", $source);
    }

    public function testCopyRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CopyRuntime.php');
        $this->assertStringContainsString('CopyJitHelper::copyArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString("lookupFunction('fread')", $source);
        $this->assertLessThan(180, \substr_count($source, "\n") + 1);
        $jitCopy = (string) file_get_contents(__DIR__.'/../../ext/standard/JitCopy.php');
        $this->assertStringContainsString('CopyRuntime::ensureLinked', $jitCopy);
    }

    public function testCopyJitHelperUsesTriggerErrorJitHelperForDirectoryWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CopyJitHelper.php');
        $this->assertStringContainsString('TriggerErrorJitHelper::warning', $source);
        $this->assertStringNotContainsString('trigger_error', $source);
    }

    public function testCopyJitHelperMatchesVmFs(): void
    {
        $from = sys_get_temp_dir().'/phpc_copy_from_'.getmypid();
        $to = sys_get_temp_dir().'/phpc_copy_to_'.getmypid();
        file_put_contents($from, 'payload');
        @unlink($to);
        $this->assertSame(VmFs::copy($from, $to) ? 1 : 0, CopyJitHelper::copyArgv($from, $to));
        $this->assertSame('payload', file_get_contents($to));
        @unlink($from);
        @unlink($to);
    }
}
