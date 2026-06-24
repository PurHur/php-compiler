<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** phpinfo/phpcredits JIT routes through PhpinfoJitHelper PHP, not LLVM HTML tables (#9256). */
final class PhpinfoJitRuntimeShrinkTest extends TestCase
{
    public function testPhpinfoJitHelperDelegatesToVmInfo(): void
    {
        $source = (string) \file_get_contents(dirname(__DIR__, 2).'/ext/standard/PhpinfoJitHelper.php');
        $this->assertStringContainsString('VmInfo::renderPhpinfoHtml', $source);
        $this->assertStringContainsString('VmInfo::renderPhpcreditsHtml', $source);
    }

    public function testStringPhpinfoRuntimeRoutesThroughPhpinfoJitHelper(): void
    {
        $source = (string) \file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/StringPhpinfoRuntime.php');
        $this->assertStringContainsString('PhpinfoJitHelper', $source);
        $this->assertStringNotContainsString('emitPhpinfoHtmlHeader', $source);
        $this->assertStringNotContainsString('emitGeneralSection', $source);
        $this->assertStringNotContainsString('emitObEchoCstr', $source);
        $this->assertStringNotContainsString('StringPhpinfoRuntimeLlvm', $source);
        $this->assertLessThan(200, \substr_count($source, "\n"), 'StringPhpinfoRuntime must be a thin bridge');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/JIT/Builtin/StringPhpinfoRuntimeLlvm.php');
    }

    public function testVmInfoExposesRenderPhpinfoForSharedSsot(): void
    {
        $source = (string) \file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmInfo.php');
        $this->assertStringContainsString('public static function renderPhpinfoHtml', $source);
        $this->assertStringContainsString('public static function renderPhpcreditsHtml', $source);
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
