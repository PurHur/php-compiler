<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getrusage JIT NestedJIT via JitVmHelperLink::ensureCompiled (#9184 / #25754 / #27551). */
final class GetrusageJitRuntimeShrinkTest extends TestCase
{
    public function testGetrusageJitHelperDelegatesToVmProcessAndNativeScalars(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/GetrusageJitHelper.php');
        $this->assertStringContainsString('VmProcess::getrusage', $source);
        $this->assertStringContainsString('VmGetrusageNative::getrusage', $source);
        $this->assertStringContainsString('function resolveOk', $source);
        $this->assertStringContainsString('function valueAt', $source);
        $this->assertStringNotContainsString('private static ?array $last', $source);
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

    public function testStringGetrusageRuntimeMaterializesHashtableFromScalars(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetrusageRuntime.php');
        $this->assertStringContainsString('GetrusageJitHelper::resolveOk', $source);
        $this->assertStringContainsString('GetrusageJitHelper::valueAt', $source);
        $this->assertStringContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertStringContainsString('__compiler_getrusage', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('GetrusageJitHelper::resolve)', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
        $this->assertLessThan(280, \substr_count($source, "\n") + 1);
    }
}
