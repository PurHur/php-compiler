<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getrusage JIT helpers route through VmProcess PHP, not struct rusage LLVM (#9184). */
final class GetrusageJitRuntimeShrinkTest extends TestCase
{
    public function testGetrusageJitHelperDelegatesToVmProcess(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/GetrusageJitHelper.php');
        $this->assertStringContainsString('VmProcess::getrusage', $source);
    }

    public function testStringGetrusageNoLongerUsesStructRusageOffsets(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetrusage.php');
        $this->assertStringContainsString('StringGetrusageRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('FIELD_OFFSETS', $source);
        $this->assertStringNotContainsString('RUSAGE_SIZE', $source);

        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetrusageRuntime.php');
        $this->assertStringContainsString('GetrusageJitHelper', $runtimeSource);
        $this->assertStringNotContainsString("lookupFunction('getrusage')", $runtimeSource);
    }
}
