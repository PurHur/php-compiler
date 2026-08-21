<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_is_resource ABI shell from Builtin\Type (#33088).
 *
 * NestedJIT/AOT bridge stays StreamLifecycleRuntime + JitStreamLifecycleKernel /
 * StreamLifecycleJitHelper / StreamGlobalsJit::implementThinIsResource. Runtime owner
 * declares module-locally (getNamedFunction first) so leftover Type empty decls cannot
 * mint is_resource.1 (#31894 / #32122).
 */
final class TypeDeadIsResourceAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnIsResourceAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33088', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_is_resource[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_is_resource (#33088)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_is_resource'",
            $type,
            'Builtin\\Type must not always-register __compiler_is_resource (#33088)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $this->assertStringContainsString('StreamLifecycle::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresIsResourceAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLifecycleKernel.php');
        $this->assertStringContainsString('#33088', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_is_resource', $owner);
        $thin = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamGlobalsJit.php');
        $this->assertStringContainsString('implementThinIsResource', $thin);
        $this->assertStringContainsString("getNamedFunction(\$abiName)", $thin);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamLifecycleJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/is_resource_.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamLifecycle(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamLifecycle::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForIsResourceAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/is_resource.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/is_resource.c');
    }
}
