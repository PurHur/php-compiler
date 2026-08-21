<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_flock ABI shell from Builtin\Type (#33104).
 *
 * NestedJIT/AOT bridge stays StreamReadRuntime / StreamReadJitHelper /
 * JitStreamReadBridgeKernel (getNamedFunction first). Runtime owner declares
 * module-locally so leftover Type empty decls cannot mint flock.1 (#31894 / #32122).
 */
final class TypeDeadFlockAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFlockAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33104', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_flock[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_flock (#33104)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_flock'",
            $type,
            'Builtin\\Type must not always-register __compiler_flock (#33104)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (strftime still Type always-on; #33213 unserialize / #33215 format_datetime dropped).
        $this->assertStringContainsString("registerFunction('__compiler_strftime'", $type);
        $this->assertStringContainsString('StreamRead::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFlockAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('#33104', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('__compiler_flock', $owner);
        $this->assertStringContainsString('implementI32Bridge', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamReadJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamReadBridgeKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFlock.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamRead(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamRead::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFlockAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/flock.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/flock.c');
    }
}
