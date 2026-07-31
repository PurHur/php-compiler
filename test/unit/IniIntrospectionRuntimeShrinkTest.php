<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** php_ini introspection JIT NestedJIT via JitVmHelperLink::ensureCompiled (#11562 / #25630). */
final class IniIntrospectionRuntimeShrinkTest extends TestCase
{
    public function testJitIniIntrospectionDelegatesToRuntime(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitIniIntrospection.php');
        $this->assertStringContainsString('IniIntrospectionRuntime', $source);
        $this->assertStringNotContainsString("lookupFunction('getenv')", $source);
        $this->assertStringNotContainsString("lookupFunction('strlen')", $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(45, $lineCount);
    }

    public function testIniIntrospectionRuntimeIsThinBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IniIntrospectionRuntime.php');
        $this->assertStringContainsString('IniIntrospectionJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString("lookupFunction('getenv')", $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(230, \substr_count($source, "\n") + 1);
    }

    public function testIniIntrospectionJitHelperDelegatesToVm(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/IniIntrospectionJitHelper.php');
        $this->assertStringContainsString('VmIniIntrospection::loadedFile', $source);
        $this->assertStringContainsString('VmIniIntrospection::scannedFiles', $source);
    }
}
