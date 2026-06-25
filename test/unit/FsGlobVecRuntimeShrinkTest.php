<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FsGlobJitHelper;
use PHPCompiler\ext\standard\VmDir;
use PHPCompiler\ext\standard\VmFsGlob;
use PHPUnit\Framework\TestCase;

/** StringFsGlobVecJit embed routes through FsGlobJitHelper PHP not libc LLVM (#11515). */
final class FsGlobVecRuntimeShrinkTest extends TestCase
{
    public function testStringFsGlobVecJitIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsGlobVecJit.php');
        $this->assertStringContainsString('FsGlobVecRuntime', $source);
        $this->assertStringContainsString('FsGlobVecStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitGlobVec', $source);
        $this->assertStringNotContainsString('emitScandirVec', $source);
        $this->assertLessThan(35, \substr_count($source, "\n") + 1);
    }

    public function testJitFsGlobUsesFsGlobJitHelperOnEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFsGlob.php');
        $this->assertStringContainsString('FsGlobVecRuntime::GLOB_HELPER', $source);
        $this->assertStringContainsString('collectFromHelper', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
    }

    public function testFsGlobJitHelperDelegatesToVmSsot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FsGlobJitHelper.php');
        $this->assertStringContainsString('VmFsGlob::glob', $source);
        $this->assertStringContainsString('VmDir::scandir', $source);
        $this->assertStringContainsString('VmFs::stringListToArray', $source);
    }

    public function testFsGlobJitHelperGlobArgvRoundTrip(): void
    {
        $ht = FsGlobJitHelper::globArgv('*.php', 0);
        $this->assertNotNull($ht);
        $this->assertGreaterThan(0, $ht->getNumElements());
    }

    public function testFsGlobJitHelperScandirArgvRoundTrip(): void
    {
        $ht = FsGlobJitHelper::scandirArgv('.', \SCANDIR_SORT_ASCENDING);
        $this->assertNotNull($ht);
        $this->assertGreaterThan(0, $ht->getNumElements());
    }

    public function testFsGlobJitHelperMatchesVmGlob(): void
    {
        $vm = VmFsGlob::glob('composer.json', 0);
        $this->assertIsArray($vm);
        $jit = FsGlobJitHelper::globArgv('composer.json', 0);
        $this->assertNotNull($jit);
        $this->assertSame(\count($vm), $jit->getNumElements());
    }

    public function testFsGlobJitHelperMatchesVmScandir(): void
    {
        $vm = VmDir::scandir('.', \SCANDIR_SORT_ASCENDING);
        $this->assertIsArray($vm);
        $jit = FsGlobJitHelper::scandirArgv('.', \SCANDIR_SORT_ASCENDING);
        $this->assertNotNull($jit);
        $this->assertSame(\count($vm), $jit->getNumElements());
    }
}
