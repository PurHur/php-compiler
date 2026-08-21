<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on __compiler_get_headers ABI shell from Builtin\Type (#33042).
 *
 * NestedJIT/AOT bridge stays GetHeadersRuntime + GetHeadersJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint get_headers.1 (#31894 / #32122).
 */
final class TypeDeadGetHeadersAbiRuntimeShrinkTest extends TestCase
{
    public function testTypeBuiltinDropsLeftoverAlwaysOnGetHeadersAbi(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33042', $type);
        $this->assertDoesNotMatchRegularExpression(
            '/addFunction\(\s*[\'\"]__compiler_get_headers[\'\"]/',
            $type,
            'Builtin\\Type must not always-declare __compiler_get_headers (#33042)'
        );
        $this->assertStringNotContainsString(
            "registerFunction('__compiler_get_headers'",
            $type,
            'Builtin\\Type must not always-register __compiler_get_headers (#33042)'
        );
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (format_datetime still Type always-on; #33213 unserialize / #33212 phpc_run_command dropped).
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('GetHeadersRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresGetHeadersAbiModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetHeadersRuntime.php');
        $this->assertStringContainsString('#33042', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('addFunction', $owner);
        $this->assertStringContainsString('__compiler_get_headers', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/GetHeadersJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGetHeaders.php');
    }

    public function testTypeInitializeStillEnsureLinksGetHeadersRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('GetHeadersRuntime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForGetHeadersAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/get_headers.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/get_headers.c');
    }
}
