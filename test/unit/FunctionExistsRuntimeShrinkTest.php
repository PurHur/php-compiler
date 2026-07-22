<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** function_exists() JIT routes through FunctionExistsJitHelper PHP not LLVM tables (#9239, #16424, #22016). */
final class FunctionExistsRuntimeShrinkTest extends TestCase
{
    public function testFunctionExistsRuntimeUsesJitHelperNotLlvmTable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FunctionExistsRuntime.php');
        $this->assertStringContainsString('FunctionExistsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('implementLookup', $source);
        $this->assertStringNotContainsString('compareNameToLiteral', $source);
        $this->assertStringNotContainsString('memcmp', $source);
    }

    public function testJitFunctionExistsDelegatesToStringFunctionExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFunctionExists.php');
        $this->assertStringContainsString('StringFunctionExists::invoke', $source);
        $this->assertStringNotContainsString('userFunctionNames', $source);
        $this->assertStringNotContainsString('JitStringCompare', $source);
        $this->assertStringNotContainsString('__compiler_builtin_function_exists', $source);
        // Soft-null coerce + normalizeString (#21281 / #20360) — keep thin, no ABI table.
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }

    public function testStringFunctionExistsUsesJitHelperNotUserFunctionLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFunctionExists.php');
        $this->assertStringContainsString('FunctionExistsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('existsArgv', $source);
        $this->assertStringNotContainsString('userFunctionNames', $source);
    }

    public function testFunctionExistsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FunctionExistsJitHelper.php');
        $this->assertStringContainsString('VmReflection::functionExists', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }
}
