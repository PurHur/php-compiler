<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamModeJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamMeta;
use PHPUnit\Framework\TestCase;

/**
 * Stream mode NestedJIT via JitVmHelperLink::ensureCompiled (#13021, #19794, #22968).
 */
final class StreamModeRuntimeShrinkTest extends TestCase
{
    public function testBuiltinStreamModeRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamModeKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StreamModeRuntime.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamModeRuntime.php');
        $this->assertStringContainsString('JitStreamModeKernel', $orchestrator);
        $this->assertStringContainsString('JitStreamModeKernel::ensureLinked', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('__phpc_stream_mode', $orchestrator);
        $this->assertLessThan(55, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamModeKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamModeKernel', $source);
        $this->assertStringContainsString('__phpc_stream_mode', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('StreamModeJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(160, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamModeKernel.php', $spine);
        $this->assertStringContainsString('StreamModeRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitStreamModeKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/StreamModeRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }

    public function testStreamModeJitHelperPlainfileRoundTrip(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc_mode_shrink');
        $this->assertNotFalse($path);
        $handle = VmFs::fopen($path, 'r+');
        $this->assertNotFalse($handle);
        $this->assertSame('r+', StreamModeJitHelper::modeForHandle($handle));
        $this->assertSame('r+', VmStreamMeta::userFacingMode($path, VmFs::handleMode($handle)));
        VmFs::fclose($handle);
        unlink($path);
    }
}
