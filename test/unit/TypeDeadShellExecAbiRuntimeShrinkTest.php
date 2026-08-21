<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on shell_exec ABI shells from Builtin\Type (#33201).
 *
 * NestedJIT/AOT bridge stays ProcessRuntime / ProcessJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint shell_exec.1 (#31894 / #32122).
 */
final class TypeDeadShellExecAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_shell_exec',
            '__compiler_escapeshellarg',
            '__compiler_escapeshellcmd',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnShellExecAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33201', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#33201)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#33201)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (format_datetime still Type always-on; #33213 unserialize / #33212 phpc_run_command dropped).
        $this->assertStringContainsString("registerFunction('__compiler_format_datetime'", $type);
        $this->assertStringContainsString('ProcessRuntime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresShellExecAbisModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessRuntime.php');
        $this->assertStringContainsString('#33201', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('implementNullableStringBridge', $owner);
        $this->assertStringContainsString('implementStringBridge', $owner);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $owner);
        }
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitShellExec.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitEscapeshellarg.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitEscapeshellcmd.php');
        foreach (['JitShellExec.php', 'JitEscapeshellarg.php', 'JitEscapeshellcmd.php'] as $jit) {
            $src = (string) file_get_contents(__DIR__.'/../../ext/standard/'.$jit);
            $this->assertStringContainsString('#33201', $src);
            $this->assertStringContainsString('ProcessRuntime::ensureLinked', $src);
        }
    }

    public function testTypeInitializeStillEnsureLinksProcessRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('ProcessRuntime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForShellExecAbis(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/shell_exec.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/shell_exec.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/escapeshellarg.c');
    }
}
