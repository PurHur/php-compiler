<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_get_resource_type ABI shell from Builtin\Type (#33183).
 *
 * NestedJIT/AOT bridge stays StreamResource / JitStreamResourceKernel (implementIfMissing).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint get_resource_type.1 (#31894 / #32122).
 */
final class TypeDeadGetResourceTypeAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnGetResourceTypeAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33183', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_get_resource_type[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_get_resource_type (#33183)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_get_resource_type'",
            $type,
            'Builtin\\Type must not always-register __compiler_get_resource_type (#33183)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (phpc_deploy_path still Type always-on; #33207 serialize_* dropped).
        $this->assertStringContainsString("registerFunction('__compiler_phpc_deploy_path'", $type);
        $this->assertStringContainsString('StreamResource::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresGetResourceTypeAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamResourceKernel.php');
        $this->assertStringContainsString('#33183', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_get_resource_type', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamResource.php');
        $this->assertStringContainsString('#33183', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGetResourceType.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamResourceKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetResourceType.php');
        $this->assertStringContainsString('StreamResource::ensureLinked', $jit);
        $this->assertStringContainsString('#33183', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStreamResource(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamResource::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForGetResourceTypeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/get_resource_type.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/get_resource_type.c');
    }
}
