<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getrusage JIT NestedJIT via JitVmHelperLink::ensureCompiled (#9184 / #25754). */
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

    public function testStringGetrusageRuntimeRoutesThroughEnsureCompiled(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetrusageRuntime.php');
        $this->assertStringContainsString('GetrusageJitHelper::resolve', $source);
        $this->assertStringContainsString('__compiler_getrusage', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
        $this->assertLessThan(185, \substr_count($source, "\n") + 1);
    }
}
