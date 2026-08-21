<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_get_resources ABI shell from Builtin\Type (#33130).
 *
 * NestedJIT/AOT bridge stays StreamResource / JitStreamResourceKernel (implementIfMissing).
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint get_resources.1 (#31894 / #32122).
 */
final class TypeDeadGetResourcesAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnGetResourcesAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33130', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_get_resources[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_get_resources (#33130)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_get_resources'",
            $type,
            'Builtin\\Type must not always-register __compiler_get_resources (#33130)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (serialize_hashtable still Type always-on; #33196 str_getcsv dropped).
        $this->assertStringContainsString("registerFunction('__compiler_serialize_hashtable'", $type);
        $this->assertStringContainsString('StreamResource::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresGetResourcesAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamResourceKernel.php');
        $this->assertStringContainsString('#33130', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_get_resources', $owner);
        $this->assertStringContainsString('implementIfMissing', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamResource.php');
        $this->assertStringContainsString('#33130', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGetResources.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamResourceKernel.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetResources.php');
        $this->assertStringContainsString('StreamResource::ensureLinked', $jit);
        $this->assertStringContainsString('#33130', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStreamResource(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamResource::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForGetResourcesAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/get_resources.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/get_resources.c');
    }
}
