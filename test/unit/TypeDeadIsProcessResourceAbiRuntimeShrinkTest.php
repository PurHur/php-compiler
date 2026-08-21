<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_is_process_resource ABI shell from Builtin\Type (#33121).
 *
 * NestedJIT/AOT bridge stays ProcessOpenEmbedBridge / ProcessOpenJitHelper
 * (implementI32Bridge). Runtime owner declares module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint
 * is_process_resource.1 (#31894 / #32122).
 */
final class TypeDeadIsProcessResourceAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnIsProcessResourceAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33121', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_is_process_resource[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_is_process_resource (#33121)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_is_process_resource'",
            $type,
            'Builtin\\Type must not always-register __compiler_is_process_resource (#33121)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (assert_fail still Type always-on; #33234 trigger_error dropped).
        $this->assertStringContainsString("registerFunction('__compiler_assert_fail'", $type);
        $this->assertStringContainsString('ProcessOpen::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresIsProcessResourceAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpenEmbedBridge.php');
        $this->assertStringContainsString('#33121', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_is_process_resource', $owner);
        $this->assertStringContainsString('implementI32Bridge', $owner);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpen.php');
        $this->assertStringContainsString('#33121', $orchestrator);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ProcessOpenJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/proc_open.php');
    }

    public function testTypeInitializeStillEnsureLinksProcessOpen(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('ProcessOpen::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForIsProcessResourceAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/is_process_resource.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/is_process_resource.c');
    }
}
