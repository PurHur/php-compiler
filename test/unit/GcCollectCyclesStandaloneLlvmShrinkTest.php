<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Standalone AOT GC registry/collect routes through PHP helpers not StandaloneLlvm (#18630). */
final class GcCollectCyclesStandaloneLlvmShrinkTest extends TestCase
{
    public function testGcCollectCyclesRuntimeUsesPhpRegistryAlways(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper', $source);
        $this->assertStringContainsString('GcCollectCyclesStandaloneJitHelper', $source);
        $this->assertStringContainsString('collectCyclesStandalone', $source);
        $this->assertStringNotContainsString('GcCollectCyclesStandaloneLlvm', $source);
        $this->assertStringNotContainsString('gc_register_entry', $source);
        $this->assertStringNotContainsString("G_OBJECTS = 'phpc_gc_objects'", $source);
        $this->assertStringNotContainsString('phpc_gc_marked', $source);
    }

    public function testStandaloneLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesStandaloneLlvm.php');
    }

    public function testGcCollectCyclesJitHelperDocumentsStandaloneBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GcCollectCyclesStandaloneJitHelper.php');
        $this->assertStringContainsString('collectCyclesStandalone', $source);
        $this->assertStringContainsString('GcCollectCyclesNativeScanJitHelper', $source);
    }
}
