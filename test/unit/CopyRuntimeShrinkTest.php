<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CopyJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** copy() JIT routes through CopyJitHelper PHP not StringFsDirJit libc LLVM (#9585). */
final class CopyRuntimeShrinkTest extends TestCase
{
    public function testStringFsDirJitDelegatesCopyToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('CopyRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitCopy', $source);
        $this->assertStringNotContainsString("lookupFunction('fopen')", $source);
    }

    public function testCopyRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CopyRuntime.php');
        $this->assertStringContainsString('CopyJitHelper::copyArgv', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString("lookupFunction('fread')", $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
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
