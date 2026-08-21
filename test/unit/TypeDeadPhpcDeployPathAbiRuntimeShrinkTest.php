<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_phpc_deploy_path ABI shell from Builtin\Type (#33225).
 *
 * NestedJIT/AOT bridge stays StringDeployPath / JitDeployPath / DeployPathJitHelper.
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
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (assert_fail_string still Type always-on; #33234 trigger_error / #33237 assert_fail dropped).
        $this->assertStringContainsString("registerFunction('__compiler_assert_fail_string'", $type);
        $this->assertStringContainsString('StringDeployPath::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPhpcDeployPathAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringDeployPath.php');
        $this->assertStringContainsString('#33225', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $owner);
        $this->assertStringContainsString('isThinStandaloneAotMain', $owner);
        $this->assertStringContainsString('__compiler_phpc_deploy_path', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/DeployPathJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitDeployPath.php');
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
