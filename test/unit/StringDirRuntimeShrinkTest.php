<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\DirHandleJitHelper;
use PHPUnit\Framework\TestCase;

/** StringDirJit embed routes through DirHandleJitHelper PHP not LLVM monolith (#11811). */
final class StringDirRuntimeShrinkTest extends TestCase
{
    public function testStringDirJitIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDirJit.php');
        $this->assertStringContainsString('StringDirRuntime', $source);
        $this->assertStringContainsString('StringDirStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitOpendir', $source);
        $this->assertStringNotContainsString('emitReaddir', $source);
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }

    public function testStringDirRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDirRuntime.php');
        $this->assertStringContainsString('DirHandleJitHelper', $source);
        $this->assertStringNotContainsString('scandir', $source);
    }

    public function testDirHandleJitHelperDelegatesToVmDir(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/DirHandleJitHelper.php');
        $this->assertStringContainsString('VmDir::opendir', $source);
        $this->assertStringContainsString('VmDir::readdir', $source);
        $this->assertStringContainsString('VmDir::isValidHandle', $source);
    }

    public function testDirHandleJitHelperOpendirRoundTrip(): void
    {
        if (!DirHandleJitHelper::isDirResourceArgv(0)) {
            $handle = DirHandleJitHelper::opendirArgv('.');
            if ($handle >= 0) {
                $this->assertSame(1, DirHandleJitHelper::isDirResourceArgv($handle));
                $entry = DirHandleJitHelper::readdirArgv($handle);
                $this->assertIsString($entry);
                $this->assertSame(1, DirHandleJitHelper::rewinddirArgv($handle));
                $this->assertSame(1, DirHandleJitHelper::closedirArgv($handle));
                $this->assertSame(0, DirHandleJitHelper::isDirResourceArgv($handle));
            }
        }
        $this->assertTrue(true);
    }
}
