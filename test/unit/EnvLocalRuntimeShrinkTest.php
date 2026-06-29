<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\EnvLocalJitHelper;
use PHPCompiler\ext\standard\GetenvJitHelper;
use PHPCompiler\VM\HashTable;
use PHPUnit\Framework\TestCase;

/** EnvLocalRuntime routes overlay through EnvLocalJitHelper/GetenvJitHelper PHP (#9814, #13431). */
final class EnvLocalRuntimeShrinkTest extends TestCase
{
    public function testStringEnvLocalDeletedAndEnvLocalRuntimeUsesJitHelper(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringEnvLocal.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/EnvLocalStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/EnvLocalOverlayTableLlvm.php');
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EnvLocalRuntime.php');
        $this->assertStringContainsString('EnvLocalJitHelper', $source);
        $this->assertStringContainsString('GetenvJitHelper', (string) file_get_contents(__DIR__.'/../../ext/standard/EnvLocalJitHelper.php'));
        $this->assertStringNotContainsString('EnvLocalStandaloneLlvm', $source);
        $this->assertStringNotContainsString('EnvLocalOverlayTableLlvm', $source);
        $this->assertStringNotContainsString("getNamedGlobal('phpc_env_local_entries')", $source);
        $this->assertStringNotContainsString("getNamedGlobal('phpc_env_local_count')", $source);
    }

    public function testStringGetenvAllUsesGetenvJitHelperFillAll(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenvAll.php');
        $this->assertStringContainsString('GetenvJitHelper::fillAllEnvironmentHashtable', $source);
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

    public function testEnvLocalRuntimeNoMergeOverlayLlvmEmitter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EnvLocalRuntime.php');
        $this->assertStringNotContainsString('emitMergeOverlay', $runtime);
        $this->assertStringNotContainsString('MERGE_OVERLAY_HELPER', $runtime);
        $this->assertStringNotContainsString('EnvLocalOverlayTableLlvm', $runtime);
        $this->assertStringNotContainsString('__compiler_env_local_sync_overlay', $runtime);
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
