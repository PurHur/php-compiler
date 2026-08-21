<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_file_put_contents ABI shell from Builtin\Type (#33043).
 *
 * NestedJIT/AOT bridge stays StringFilePutContents + FilePutContentsJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint file_put_contents.1 (#31894 / #32122).
 */
final class TypeDeadFilePutContentsAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnFilePutContentsAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33043', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'"]__compiler_file_put_contents[\'"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_file_put_contents (#33043)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_file_put_contents'",
            $type,
            'Builtin\\Type must not always-register __compiler_file_put_contents (#33043)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (format_datetime still Type always-on; #33213 unserialize / #33212 phpc_run_command dropped).
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('StringFilePutContents::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFilePutContentsAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilePutContents.php');
        $this->assertStringContainsString('#33043', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_file_put_contents', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/FilePutContentsJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitFilePutContents.php');
    }

    public function testTypeInitializeStillEnsureLinksFilePutContentsRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringFilePutContents::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFilePutContentsAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/file_put_contents.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/file_put_contents.c');
    }
}
