<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * __compiler_phpc_deploy_path JIT routes through DeployPathJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#9309 / #27037 / peer #27033).
 */
final class DeployPathRuntimeShrinkTest extends TestCase
{
    public function testDeployPathJitHelperDelegatesToDeployRoot(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/DeployPathJitHelper.php');
        $this->assertStringContainsString('DeployRoot::resolvePath', $source);
    }

    public function testStringDeployPathRoutesThroughDeployPathJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDeployPath.php');
        $this->assertStringContainsString('DeployPathJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(120, \substr_count($source, "\n"), 'StringDeployPath must be a thin bridge');
    }

    public function testSpineBundleIncludesDeployPathJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('DeployPathJitHelper.php', $spine);
        $this->assertStringContainsString('StringDeployPath.php', $spine);
    }
}
