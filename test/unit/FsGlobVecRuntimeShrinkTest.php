<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FsGlobJitHelper;
use PHPCompiler\ext\standard\VmDir;
use PHPCompiler\ext\standard\VmFsGlob;
use PHPUnit\Framework\TestCase;

/**
 * StringFsGlobVecJit: always FsGlobVecRuntime + NestedJIT libc leaf (#11515, #29986).
 */
final class FsGlobVecRuntimeShrinkTest extends TestCase
{
    public function testStringFsGlobVecJitHasNoThinAotKernelFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsGlobVecJit.php');
        $this->assertStringContainsString('FsGlobVecRuntime::ensureLinked', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitFsGlobKernel::implement', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementForThinAot', $source);
        $this->assertStringNotContainsString('FsGlobVecStandaloneLlvm', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFsGlobKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/FsGlobVecStandaloneLlvm.php');
    }

    public function testFsGlobVecRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FsGlobVecRuntime.php');
        $this->assertStringContainsString('FsGlobJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertLessThan(70, \substr_count($source, "\n") + 1);
    }

    public function testJitFsGlobUsesHelperUnlessNestedJitLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFsGlob.php');
        $this->assertStringContainsString('__phpc_glob_vec', $source);
        $this->assertStringContainsString('collectList', $source);
        $this->assertStringContainsString('collectFromHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('FsGlobVecRuntime::GLOB_HELPER', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
    }

    public function testJitFsGlobKernelEmitsLibcCollectors(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFsGlobKernel.php');
        $this->assertStringContainsString('__phpc_glob_vec', $source);
        $this->assertStringContainsString('__phpc_scandir_vec', $source);
        $this->assertStringContainsString("'glob'", $source);
        $this->assertStringContainsString("'scandir'", $source);
        $this->assertStringNotContainsString('implementForThinAot', $source);
        $this->assertStringNotContainsString('runtime/', $source);
    }

    public function testFsGlobJitHelperUsesHostPeel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FsGlobJitHelper.php');
        $this->assertStringContainsString('\\glob(', $source);
        $this->assertStringContainsString('\\scandir(', $source);
        $this->assertStringNotContainsString('VmFsGlob::glob', $source);
        $this->assertStringNotContainsString('VmDir::scandir', $source);
        $this->assertStringContainsString('?array', $source);
        $this->assertStringNotContainsString('VmFs::stringListToArray', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\VM\\HashTable', $source);
    }

    public function testFsGlobJitHelperGlobArgvRoundTrip(): void
    {
        $list = FsGlobJitHelper::globArgv('composer.*', 0);
        $this->assertNotNull($list);
        $this->assertIsArray($list);
        $this->assertGreaterThan(0, \count($list));
    }

    public function testFsGlobJitHelperScandirArgvRoundTrip(): void
    {
        $list = FsGlobJitHelper::scandirArgv('.', \SCANDIR_SORT_ASCENDING);
        $this->assertNotNull($list);
        $this->assertIsArray($list);
        $this->assertGreaterThan(0, \count($list));
    }

    public function testFsGlobJitHelperMatchesVmGlob(): void
    {
        $vm = VmFsGlob::glob('composer.json', 0);
        $this->assertIsArray($vm);
        $jit = FsGlobJitHelper::globArgv('composer.json', 0);
        $this->assertNotNull($jit);
        $this->assertSame(\count($vm), \count($jit));
    }

    public function testFsGlobJitHelperMatchesVmScandir(): void
    {
        $vm = VmDir::scandir('.', \SCANDIR_SORT_ASCENDING);
        $this->assertIsArray($vm);
        $jit = FsGlobJitHelper::scandirArgv('.', \SCANDIR_SORT_ASCENDING);
        $this->assertNotNull($jit);
        $this->assertSame(\count($vm), \count($jit));
    }

    public function testContextWhitelistsGlobScandirNestedJitLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'glob'", $source);
        $this->assertStringContainsString("'scandir'", $source);
        $this->assertStringContainsString('#29986', $source);
    }

    public function testIteratorThinBridgesCallKernelDirectly(): void
    {
        $gi = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GlobIteratorSnapshotRuntime.php');
        $di = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DirectoryIteratorSnapshotRuntime.php');
        $this->assertStringContainsString('JitFsGlobKernel::implement', $gi);
        $this->assertStringContainsString('JitFsGlobKernel::implement', $di);
    }
}
