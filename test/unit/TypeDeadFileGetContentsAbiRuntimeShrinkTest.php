<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_file_get_contents ABI shell from Builtin\Type (#33030).
 *
 * NestedJIT/AOT bridge stays StringFileGetContents + FileGetContentsJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint file_get_contents.1 (#31894 / #32122).
 */
final class TypeDeadFileGetContentsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFileGetContentsAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33030', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_file_get_contents[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_file_get_contents (#33030)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_file_get_contents'",
            $type,
            'Builtin\\Type must not always-register __compiler_file_get_contents (#33030)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_fwrite'", $type);
        $this->assertStringContainsString('StringFileGetContents::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFileGetContentsAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFileGetContents.php');
        $this->assertStringContainsString('#33030', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_file_get_contents', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/FileGetContentsJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFileGetContents.php');
    }

    public function testTypeInitializeStillEnsureLinksFileGetContentsRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringFileGetContents::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFileGetContentsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/file_get_contents.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/file_get_contents.c');
    }
}
