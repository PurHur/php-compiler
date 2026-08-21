<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fread ABI shell from Builtin\Type (#33055).
 *
 * NestedJIT/AOT bridge stays StreamIoRuntime + StreamIoJitHelper / JitStreamIoKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint fread.1 (#31894 / #32122).
 */
final class TypeDeadFreadAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFreadAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33055', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fread[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fread (#33055)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fread'",
            $type,
            'Builtin\\Type must not always-register __compiler_fread (#33055)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (proc_close still Type always-on for this peer guard).
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('StreamIo::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFreadAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('#33055', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_fread', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamIoJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamIoRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamIo::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFreadAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fread.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fread.c');
    }
}
