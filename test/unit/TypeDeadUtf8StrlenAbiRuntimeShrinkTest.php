<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on utf8_strlen/utf8_valid ABI shells from Builtin\Type (#33001).
 *
 * NestedJIT/AOT bridge stays StringUtf8Runtime + StringUtf8StrlenJit / StringUtf8ValidJit.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint utf8_strlen.1 (#31894 / #32122).
 */
final class TypeDeadUtf8StrlenAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_utf8_strlen',
            '__compiler_utf8_valid',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnUtf8StrlenAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33001', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#33001)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#33001)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_proc_close'", $type);
        $this->assertStringContainsString('StringUtf8Runtime::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresUtf8StrlenAbisModuleLocally(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Runtime.php');
        $strlen = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8StrlenJit.php');
        $valid = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8ValidJit.php');
        $this->assertStringContainsString('#33001', $runtime);
        $this->assertStringContainsString('#33001', $strlen);
        $this->assertStringContainsString('#33001', $valid);
        $this->assertStringContainsString('getNamedFunction', $strlen);
        $this->assertStringContainsString('getNamedFunction', $valid);
        $this->assertStringContainsString('declareAbi', $strlen);
        $this->assertStringContainsString('declareAbi', $valid);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $runtime, "{$sym} must remain owned by StringUtf8Runtime (#33001)");
        }
        $this->assertFileExists(__DIR__.'/../../ext/standard/Utf8JitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/mbstring/JitMbStrlen.php');
        $this->assertFileExists(__DIR__.'/../../ext/mbstring/JitMbCheckEncoding.php');
    }

    public function testTypeInitializeStillEnsureLinksUtf8Runtime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringUtf8Runtime::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForUtf8StrlenAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/utf8_strlen.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/utf8_strlen.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/utf8_valid.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/utf8_valid.c');
    }
}
