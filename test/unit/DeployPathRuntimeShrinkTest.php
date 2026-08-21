<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * __compiler_phpc_deploy_path thin AOT uses DeployPathLlvm (#33244);
 * VM SSOT stays DeployPathJitHelper / DeployRoot (#9309 / #27037).
 */
final class DeployPathRuntimeShrinkTest extends TestCase
{
    public function testDeployPathJitHelperDelegatesToDeployRoot(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/DeployPathJitHelper.php');
        $this->assertStringContainsString('DeployRoot::resolvePath', $source);
    }

    public function testStringDeployPathRoutesThroughDeployPathLlvm(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDeployPath.php');
        $this->assertStringContainsString('DeployPathLlvm::implement', $source);
        $this->assertStringContainsString('BasicBlockHelper::scopeLoweringToFunction', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(120, \substr_count($source, "\n"), 'StringDeployPath must be a thin bridge');
        $llvm = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DeployPathLlvm.php');
        $this->assertStringContainsString('StringGetenv::invokeNestedLeaf', $llvm);
        $this->assertStringContainsString('JitStringConcat::concat', $llvm);
    }

    public function testSpineBundleIncludesDeployPathJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('DeployPathJitHelper.php', $spine);
        $this->assertStringContainsString('StringDeployPath.php', $spine);
        $this->assertStringContainsString('DeployPathLlvm.php', $spine);
    }
}
