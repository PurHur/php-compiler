<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StatPath NestedJIT ABI bridges quarantined in ext/standard (#9112, #19849).
 */
final class StatPathRuntimeShrinkTest extends TestCase
{
    public function testJitStatUsesStatPathRuntimeNotGlibcOffsets(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStat.php');
        $this->assertStringContainsString('StatPathRuntime::', $source);
        $this->assertStringNotContainsString('STAT_BUF_SIZE', $source);
        $this->assertStringNotContainsString('STAT_MODE_OFFSET', $source);
        $this->assertStringNotContainsString("lookupFunction('stat')", $source);
        $this->assertStringNotContainsString("lookupFunction('lstat')", $source);
        $this->assertStringNotContainsString("lookupFunction('access')", $source);
        $this->assertStringNotContainsString('STATVFS_', $source);
        $this->assertLessThan(400, \substr_count($source, "\n"), 'JitStat must shrink after PHP bridge migration');
    }

    public function testBuiltinStatPathRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStatPathKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StatPathRuntime.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StatPathRuntimeLibc.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StatPathRuntime.php');
        $this->assertStringContainsString('JitStatPathKernel', $orchestrator);
        $this->assertStringContainsString('JitStatPathKernel::ensureLinked', $orchestrator);
        $this->assertStringContainsString('JitStatPathKernel::ensureStandaloneBodies', $orchestrator);
        $this->assertStringContainsString('JitStatPathKernel::implement', $orchestrator);
        $this->assertStringContainsString('FN_EXISTS = JitStatPathKernel::FN_EXISTS', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $orchestrator);
        $this->assertStringNotContainsString('implementPathBoolBridge', $orchestrator);
        $this->assertStringNotContainsString('StatPathJitHelper', $orchestrator);
        $this->assertLessThan(60, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStatPathKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStatPathKernel', $source);
        $this->assertStringContainsString('__phpc_jit_path_exists', $source);
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('dirname(__DIR__, 2)', $source);
        $this->assertStringContainsString('StatPathJitHelper', $source);
        $this->assertStringContainsString('StatFieldsJitHelper', $source);
        $this->assertStringContainsString('JitStatKernel', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(420, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStatPathKernel.php', $spine);
        $this->assertStringContainsString('StatPathRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitStatPathKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/StatPathRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }

    public function testStatPathJitHelperUsesStatModeKernelNotExternalVmStatPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StatPathJitHelper.php');
        $this->assertStringContainsString('phpc_stat_mode_kernel', $source);
        $this->assertStringContainsString('phpc_access_kernel', $source);
        $this->assertStringNotContainsString('VmStatPath::', $source);
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStatKernel.php');
        $this->assertStringContainsString("lookupFunction(\$statFn)", $kernel);
        $this->assertStringContainsString("lookupFunction('access')", $kernel);
        $this->assertStringContainsString('STAT_MODE_OFFSET', $kernel);
    }

    public function testStatFieldsJitHelperDelegatesToVmStatCache(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StatFieldsJitHelper.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertStringContainsString('VmStatCache::lstat', $source);
        $this->assertStringContainsString('VmFs::fileType', $source);
        $this->assertStringContainsString('VmFsDiskNative::', $source);
    }
}
