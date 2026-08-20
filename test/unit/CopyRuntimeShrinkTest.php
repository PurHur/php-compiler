<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CopyJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/**
 * copy() JIT routes through CopyLibcRuntime stdio not StringFsDirJit (#9585, #32466).
 * Thin AOT cannot use NestedJIT CopyJitHelper (peer TouchLibcRuntime #28995).
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

    public function testCopyRuntimeUsesCopyLibcRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CopyRuntime.php');
        $this->assertStringContainsString('CopyLibcRuntime::emit', $source);
        $this->assertStringNotContainsString('CopyJitHelper::copyArgv', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertLessThan(130, \substr_count($source, "\n") + 1);
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CopyLibcRuntime.php');
        $this->assertStringContainsString("lookupFunction('fread')", $libc);
        $this->assertStringContainsString("lookupFunction('fwrite')", $libc);
        $jitCopy = (string) file_get_contents(__DIR__.'/../../ext/standard/JitCopy.php');
        $this->assertStringContainsString('CopyRuntime::ensureLinked', $jitCopy);
    }

    public function testCopyJitHelperUsesTriggerErrorJitHelperForDirectoryWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CopyJitHelper.php');
        $this->assertStringContainsString('TriggerErrorJitHelper::warning', $source);
        $this->assertStringNotContainsString('trigger_error', $source);
        // NestedJIT-safe: file_get_contents/file_put_contents leaves, not unbound @\copy (#32466).
        $this->assertStringContainsString('file_get_contents', $source);
        $this->assertStringContainsString('file_put_contents', $source);
        $this->assertStringNotContainsString('return VmFs::copy', $source);
        $this->assertStringNotContainsString('VmFs::copy($from', $source);
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
