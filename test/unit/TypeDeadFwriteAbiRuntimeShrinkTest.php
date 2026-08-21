<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_fwrite ABI shell from Builtin\Type (#33048).
 *
 * NestedJIT/AOT bridge stays StreamIoRuntime + StreamIoJitHelper / JitStreamIoKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint fwrite.1 (#31894 / #32122).
 */
final class TypeDeadFwriteAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFwriteAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33048', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_fwrite[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_fwrite (#33048)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_fwrite'",
            $type,
            'Builtin\\Type must not always-register __compiler_fwrite (#33048)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (strftime still Type always-on; #33213 unserialize / #33215 format_datetime dropped).
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('StreamIo::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFwriteAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('#33048', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_fwrite', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamIoJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFwrite.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamIo(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamIo::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFwriteAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fwrite.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fwrite.c');
    }
}
