<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SessionLifecycle LLVM quarantined in ext/standard (#9446, #19896).
 */
final class SessionLifecycleRuntimeShrinkTest extends TestCase
{
    public function testBuiltinSessionLifecycleRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSessionLifecycleKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/SessionLifecycleRuntime.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionLifecycleRuntime.php');
        $this->assertStringContainsString('JitSessionLifecycleKernel', $orchestrator);
        $this->assertStringContainsString('JitSessionLifecycleKernel::ensureLinked', $orchestrator);
        $this->assertStringNotContainsString('PHPLLVM', $orchestrator);
        $this->assertStringNotContainsString('implementStandaloneRuntime', $orchestrator);
        $this->assertStringNotContainsString('implementGenerateNewId', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertLessThan(50, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelHoldsLifecycleLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSessionLifecycleKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitSessionLifecycleKernel', $source);
        $this->assertStringContainsString('__phpc_session_generate_new_id', $source);
        $this->assertStringContainsString('implementStandaloneRuntime', $source);
        $this->assertStringContainsString('ensureRandomIdStringLinked', $source);
        $this->assertStringContainsString('phpc_session_random_id_string', $source);
        $this->assertStringContainsString('SessionStorageGlobals::emitCallEnsureDefaults', $source);
        $this->assertStringNotContainsString('emitEnsureDefaultSessionName', $source);
        $this->assertStringNotContainsString('__compiler_random_bytes', $source);
        $this->assertStringNotContainsString('sgen_loop_head', $source);
        $this->assertStringNotContainsString('HEX_TABLE', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(450, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSessionLifecycleKernel.php', $spine);
        $this->assertStringContainsString('SessionLifecycleRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitSessionLifecycleKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/SessionLifecycleRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }
}
