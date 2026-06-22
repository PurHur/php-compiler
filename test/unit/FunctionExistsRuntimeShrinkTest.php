<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** FunctionExistsRuntime must route through FunctionExistsJitHelper PHP, not LLVM memcmp table (#9239). */
final class FunctionExistsRuntimeShrinkTest extends TestCase
{
    public function testFunctionExistsRuntimeUsesJitHelperNotLlvmTable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FunctionExistsRuntime.php');
        $this->assertStringContainsString('FunctionExistsJitHelper', $source);
        $this->assertStringNotContainsString('implementLookup', $source);
        $this->assertStringNotContainsString('compareNameToLiteral', $source);
        $this->assertStringNotContainsString('memcmp', $source);
    }

    public function testJitFunctionExistsUsesFunctionExistsRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFunctionExists.php');
        $this->assertStringContainsString('StringFunctionExists::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_builtin_function_exists', $source);
    }
}
