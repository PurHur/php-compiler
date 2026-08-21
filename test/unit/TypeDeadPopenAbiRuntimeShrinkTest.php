<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_popen ABI shell from Builtin\Type (#33100).
 *
 * NestedJIT/AOT bridge stays StreamIoRuntime / StreamIoJitHelper /
 * JitStreamIoKernel (declareRuntimeFn + implementBinaryStringBridge). Runtime
 * owner declares module-locally (getNamedFunction first) so leftover Type empty
 * decls cannot mint popen.1 (#31894 / #32122).
 */
final class TypeDeadPopenAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnPopenAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33100', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_popen[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_popen (#33100)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_popen'",
            $type,
            'Builtin\\Type must not always-register __compiler_popen (#33100)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (proc_close still Type always-on for this peer guard).
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('StreamIo::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPopenAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('#33100', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_popen', $owner);
        $this->assertStringContainsString('declareRuntimeFn', $owner);
        $this->assertStringContainsString('implementBinaryStringBridge', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamIoJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamIo(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamIo::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForPopenAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/popen.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/popen.c');
    }
}
