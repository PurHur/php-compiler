<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** GcCollectCyclesNative C ABI shim removed — GcCollectCyclesRuntime owns all GC LLVM (#10087). */
final class GcCollectCyclesNativeShrinkTest extends TestCase
{
    public function testGcCollectCyclesNativeFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesNative.php');
    }

    public function testJitAndRefcountUseRuntimeNotNative(): void
    {
        foreach (
            [
                __DIR__.'/../../lib/JIT.php',
                __DIR__.'/../../lib/JIT/Builtin/Type/Object_.php',
            ] as $path
        ) {
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString('GcCollectCyclesRuntime::ensureLinked', $source);
            $this->assertStringNotContainsString('GcCollectCyclesNative', $source);
        }

        $refcount = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Refcount.php');
        $this->assertStringContainsString('GcCollectCyclesRuntime::ensureDeclarations', $refcount);
        $this->assertStringNotContainsString('GcCollectCyclesNative', $refcount);
    }

    public function testSpineSmokeDroppedNativeRequire(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GcCollectCyclesRuntime.php', $source);
        $this->assertStringNotContainsString('GcCollectCyclesNative.php', $source);
    }
}
