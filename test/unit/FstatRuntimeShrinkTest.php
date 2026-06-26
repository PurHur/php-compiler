<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FstatJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPCompiler\ext\standard\VmStreamFstat;
use PHPUnit\Framework\TestCase;

/** fstat() JIT routes through FstatJitHelper PHP not stream_path+stat LLVM (#10460). */
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
        $this->assertLessThan(150, \substr_count($source, "\n"), 'StreamFstatRuntime must stay thin');
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
        VmPhpMemoryStream::close($handle);
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
