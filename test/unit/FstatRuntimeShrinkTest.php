<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FstatJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPCompiler\ext\standard\VmStreamFstat;
use PHPUnit\Framework\TestCase;

/** fstat() JIT via FstatJitHelper + JitVmHelperLink::ensureCompiled (#10460, #24586). */
final class FstatRuntimeShrinkTest extends TestCase
{
    public function testFstatJitRoutesThroughStreamFstatRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFstat.php');
        $this->assertStringContainsString('StreamFstat::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_fstat', $source);
        $this->assertStringNotContainsString('JitStatArray::invoke', $source);
        $this->assertStringNotContainsString('__phpc_stream_path', $source);
    }

    public function testStreamFstatRuntimeUsesFstatJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamFstatRuntime.php');
        $this->assertStringContainsString('FstatJitHelper', $source);
        $this->assertStringContainsString('__compiler_fstat', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringContainsString('forceLibcFstat', $source);
        $this->assertStringContainsString('fstat_libc_entry', $source);
        $this->assertStringContainsString("lookupFunction('fstat')", $source);
        $this->assertStringNotContainsString('fstatFdArgv', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(360, \substr_count($source, "\n"), 'StreamFstatRuntime must stay a bridge (+ thin AOT libc fstat)');
    }

    public function testVmStreamFstatMemorySize(): void
    {
        $handle = VmPhpMemoryStream::open('php://memory', 'w+b');
        $this->assertNotFalse($handle);
        VmPhpMemoryStream::write($handle, 'abc', 3);
        $stat = VmStreamFstat::forHandle($handle);
        $this->assertIsArray($stat);
        $this->assertSame(3, $stat['size']);
        $this->assertSame(3, $stat[7]);
        $this->assertSame(33206, $stat['mode'], 'php://memory mode 100666 octal (#18402)');
        VmPhpMemoryStream::close($handle);
    }


    public function testFstatFdArgvRejectsNegativeFd(): void
    {
        $this->assertNull(FstatJitHelper::fstatFdArgv(-1));
    }

    public function testFstatFdArgvViaOpenFd(): void
    {
        if (!\class_exists(\FFI::class)) {
            $this->markTestSkipped('FFI required for libc open/fileno probe');
        }
        $path = sys_get_temp_dir().'/phpc_fstat_fd_'.getmypid().'.txt';
        file_put_contents($path, 'xy');
        try {
            $ffi = \FFI::cdef('int open(const char *pathname, int flags); int close(int fd);', 'libc.so.6');
            $fd = (int) $ffi->open($path, 0);
            $this->assertGreaterThanOrEqual(0, $fd);
            $ht = FstatJitHelper::fstatFdArgv($fd);
            $ffi->close($fd);
            $this->assertNotNull($ht);
            $size = $ht->find('size');
            $this->assertNotNull($size);
            $this->assertSame(2, $size->toInt());
        } finally {
            @unlink($path);
        }
    }

    public function testFstatJitHelperDelegatesToVmFs(): void
    {
        $handle = VmFs::adoptStreamResource(fopen('php://memory', 'r+b'));
        $this->assertNotFalse($handle);
        fwrite(VmFs::lookupResource($handle), 'z');
        $ht = FstatJitHelper::fstatArgv($handle);
        $this->assertNotNull($ht);
        $size = $ht->find('size');
        $this->assertNotNull($size);
        $this->assertSame(1, $size->toInt());
    }
}
