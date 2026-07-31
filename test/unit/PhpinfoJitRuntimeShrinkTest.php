<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** phpinfo/phpcredits JIT routes through PhpinfoJitHelper via JitVmHelperLink, not hand-rolled NestedJIT (#9256, #25931). */
final class PhpinfoJitRuntimeShrinkTest extends TestCase
{
    public function testPhpinfoJitHelperDelegatesToVmInfo(): void
    {
        $source = (string) \file_get_contents(dirname(__DIR__, 2).'/ext/standard/PhpinfoJitHelper.php');
        $this->assertStringContainsString('VmInfo::renderPhpinfo', $source);
        $this->assertStringContainsString('VmInfo::renderPhpcreditsText', $source);
    }

    public function testStringPhpinfoRuntimeRoutesThroughPhpinfoJitHelper(): void
    {
        $source = (string) \file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/StringPhpinfoRuntime.php');
        $this->assertStringContainsString('PhpinfoJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
        $this->assertStringNotContainsString('emitPhpinfoHtmlHeader', $source);
        $this->assertStringNotContainsString('emitGeneralSection', $source);
        $this->assertStringNotContainsString('emitObEchoCstr', $source);
        $this->assertStringNotContainsString('StringPhpinfoRuntimeLlvm', $source);
        $this->assertLessThan(160, \substr_count($source, "\n") + 1, 'StringPhpinfoRuntime must be a thin bridge');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/JIT/Builtin/StringPhpinfoRuntimeLlvm.php');
    }

    public function testVmInfoExposesRenderPhpinfoForSharedSsot(): void
    {
        $source = (string) \file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmInfo.php');
        $this->assertStringContainsString('public static function renderPhpinfo', $source);
        $this->assertStringContainsString('public static function renderPhpinfoText', $source);
        $this->assertStringContainsString('public static function renderPhpcreditsText', $source);
    }

    public function testJitInfoRoutesThroughStringPhpinfoRuntime(): void
    {
        $source = (string) \file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitInfo.php');
        $this->assertStringContainsString('StringPhpinfoRuntime::ensureLinked', $source);
    }

    public function testSpineBundleOmitsDeletedStringPhpinfoRuntimeLlvm(): void
    {
        $spine = (string) \file_get_contents(dirname(__DIR__, 2).'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('StringPhpinfoRuntimeLlvm.php', $spine);
        $this->assertStringContainsString('PhpinfoJitHelper.php', $spine);
    }
}
