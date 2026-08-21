<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_tmpfile ABI shell from Builtin\Type (#33067).
 *
 * NestedJIT/AOT bridge stays StreamIoRuntime + StreamIoJitHelper / JitStreamIoKernel.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint tmpfile.1 (#31894 / #32122).
 */
final class TypeDeadTmpfileAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnTmpfileAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33067', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_tmpfile[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_tmpfile (#33067)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_tmpfile'",
            $type,
            'Builtin\\Type must not always-register __compiler_tmpfile (#33067)'
        );
        // No further Type always-on leftover after #33267 exit/abort drop.
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $this->assertStringContainsString('StreamIo::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresTmpfileAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('#33067', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_tmpfile', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/StreamIoJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
    }

    public function testTypeInitializeStillEnsureLinksStreamIoRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StreamIo::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForTmpfileAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/tmpfile.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/tmpfile.c');
    }
}
