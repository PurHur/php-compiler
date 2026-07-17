<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\EnvLocalJitHelper;
use PHPCompiler\ext\standard\GetenvJitHelper;
use PHPCompiler\VM\HashTable;
use PHPUnit\Framework\TestCase;

/**
 * EnvLocal NestedJIT ABI bridges quarantined in ext/standard (#9814, #13431, #19809).
 */
final class EnvLocalRuntimeShrinkTest extends TestCase
{
    public function testBuiltinEnvLocalRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitEnvLocalKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/EnvLocalRuntime.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringEnvLocal.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/EnvLocalStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/EnvLocalOverlayTableLlvm.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EnvLocalRuntime.php');
        $this->assertStringContainsString('JitEnvLocalKernel', $orchestrator);
        $this->assertStringContainsString('JitEnvLocalKernel::ensureLinked', $orchestrator);
        $this->assertStringContainsString('JitEnvLocalKernel::ensureBootstrapAotStubLinked', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('__compiler_env_local_lookup', $orchestrator);
        $this->assertStringNotContainsString('emitMergeOverlay', $orchestrator);
        $this->assertLessThan(45, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnvLocalKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitEnvLocalKernel', $source);
        $this->assertStringContainsString('__compiler_env_local_lookup', $source);
        $this->assertStringContainsString('__compiler_env_register_putenv', $source);
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('dirname(__DIR__, 2)', $source);
        $this->assertStringContainsString('EnvLocalJitHelper', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(400, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitEnvLocalKernel.php', $spine);
        $this->assertStringContainsString('EnvLocalRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitEnvLocalKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/EnvLocalRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }

    public function testStringGetenvAllUsesGetenvJitHelperFillAll(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenvAll.php');
        $this->assertStringContainsString('GetenvJitHelper::fillAllEnvironmentHashtable', $source);
        $this->assertStringContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('EnvLocalRuntime::emitMergeOverlay', $source);
        $this->assertStringNotContainsString('emitLocalOverlay', $source);
        $this->assertStringNotContainsString('phpc_env_local_entries', $source);
    }

    public function testEnvLocalJitHelperDelegatesToGetenvJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/EnvLocalJitHelper.php');
        $this->assertStringContainsString('GetenvJitHelper::getenv', $source);
        $this->assertStringContainsString('GetenvJitHelper::putenv', $source);
        $this->assertStringContainsString('GetenvJitHelper::mergeLocalOverlayInto', $source);
        $this->assertStringNotContainsString('Variable::string', $source);
    }

    public function testMergeLocalOverlayIntoWritesLocalPutenvEntries(): void
    {
        if (\function_exists('putenv')) {
            putenv('PHP_COMPILER_TEST_OVERLAY=1');
        }
        GetenvJitHelper::putenv('PHPC_JIT_OVERLAY_TEST=overlay_value');
        $ht = new HashTable();
        EnvLocalJitHelper::mergeLocalOverlayInto($ht);
        $this->assertSame('overlay_value', $ht->find('PHPC_JIT_OVERLAY_TEST')?->toString());
    }
}
