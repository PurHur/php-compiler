<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StatPath NestedJIT via JitVmHelperLink::ensureCompiledBundle (#23297 / peer #23284).
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
        $this->assertStringNotContainsString('ensureJitHelpersCompiled', $orchestrator);
        $this->assertLessThan(60, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesJitVmHelperLinkBundle(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStatPathKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStatPathKernel', $source);
        $this->assertStringContainsString('__phpc_jit_path_exists', $source);
        $this->assertStringContainsString('StatPathJitHelper', $source);
        $this->assertStringContainsString('StatFieldsJitHelper', $source);
        $this->assertStringContainsString('helperFunction', $source);
        $this->assertStringContainsString('StatCacheRuntime::ensureLinked', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('JitStatKernel::longField', $source);
        $this->assertStringContainsString('#27013', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('kernelPathBool', $source);
        $this->assertStringNotContainsString('kernelModeType', $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(360, \substr_count($source, "\n") + 1);
    }

    public function testContextAllowlistsStatPathKernelsForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_stat_mode_kernel', $source);
        $this->assertStringContainsString('phpc_access_kernel', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStatPathKernel.php', $spine);
        $this->assertStringContainsString('StatPathRuntime.php', $spine);
        $this->assertStringContainsString('XsltPhpFunctionBridge.php', $spine);
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
        $this->assertStringContainsString('LONG_FIELD_LAYOUT', $kernel);
        $this->assertStringContainsString('ensureLongFieldStandalone', $kernel);
        $this->assertStringContainsString('#27013', $kernel);
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
