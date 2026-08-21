<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_phpc_deploy_path ABI shell from Builtin\Type (#33225).
 *
 * Thin AOT body is DeployPathLlvm (#33244) — no NestedJIT of DeployPathJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first, then addFunction if absent)
 * so leftover Type empty decls cannot mint phpc_deploy_path.1 (#31894 / #32122).
 */
final class TypeDeadPhpcDeployPathAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPhpcDeployPathAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33225', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_phpc_deploy_path[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_phpc_deploy_path (#33225)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_phpc_deploy_path'",
            $type,
            'Builtin\\Type must not always-register __compiler_phpc_deploy_path (#33225)'
        );
        // No further Type always-on leftover after exit/abort drop (#33267).
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]exit[\'"]/',
            $type,
            'Builtin\\Type must not always-declare exit (#33267)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]abort[\'"]/',
            $type,
            'Builtin\\Type must not always-declare abort (#33267)'
        );
        $this->assertStringContainsString('StringDeployPath::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPhpcDeployPathAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDeployPath.php');
        $this->assertStringContainsString('#33225', $owner);
        $this->assertStringContainsString('#33244', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('DeployPathLlvm::implement', $owner);
        $this->assertStringContainsString('scopeLoweringToFunction', $owner);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $owner);
        $this->assertStringNotContainsString('JitNestedHelperCoerce::callHelper', $owner);
        $this->assertStringContainsString('__compiler_phpc_deploy_path', $owner);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/DeployPathLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/DeployPathJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitDeployPath.php');
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DeployPathLlvm.php');
        $this->assertStringContainsString('#33244', $llvm);
        $this->assertStringContainsString('StringGetenv::invokeNestedLeaf', $llvm);
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDeployPath.php');
        $this->assertStringContainsString('#33225', $jit);
        $this->assertStringContainsString('StringDeployPath::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringDeployPath(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringDeployPath::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForPhpcDeployPathAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_deploy_path.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/phpc_deploy_path.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_phpc_deploy_path.c');
    }
}
