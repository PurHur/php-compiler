<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_proc_open ABI shell from Builtin\Type (#33105).
 *
 * NestedJIT/AOT bridge stays ProcessOpenEmbedBridge / ProcessOpenJitHelper /
 * JitProcOpen (implementProcOpenBridge). Runtime owner declares module-locally
 * (getNamedFunction first) so leftover Type empty decls cannot mint proc_open.1
 * (#31894 / #32122).
 */
final class TypeDeadProcOpenAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnProcOpenAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33105', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_proc_open[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_proc_open (#33105)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_proc_open'",
            $type,
            'Builtin\\Type must not always-register __compiler_proc_open (#33105)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Remaining Type always-on: exit/abort (session ABI shells dropped #33261).
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString('ProcessOpen::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresProcOpenAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpenEmbedBridge.php');
        $this->assertStringContainsString('#33105', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_proc_open', $owner);
        $this->assertStringContainsString('implementProcOpenBridge', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ProcessOpenJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitProcOpen.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/proc_open.php');
    }

    public function testTypeInitializeStillEnsureLinksProcessOpen(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('ProcessOpen::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForProcOpenAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/proc_open.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/proc_open.c');
    }
}
