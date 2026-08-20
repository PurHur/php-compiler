<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_readfile ABI shell from Builtin\Type (#33021).
 *
 * NestedJIT/AOT bridge stays StringReadfile + ReadfileJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint readfile.1 (#31894 / #32122).
 */
final class TypeDeadReadfileAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnReadfileAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33021', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_readfile[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_readfile (#33021)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_readfile'",
            $type,
            'Builtin\\Type must not always-register __compiler_readfile (#33021)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_file_get_contents'", $type);
        $this->assertStringContainsString('StringReadfile::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresReadfileAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringReadfile.php');
        $this->assertStringContainsString('#33021', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_readfile', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ReadfileJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/readfile.php');
    }

    public function testTypeInitializeStillEnsureLinksReadfileRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringReadfile::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForReadfileAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/readfile.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/readfile.c');
    }
}
