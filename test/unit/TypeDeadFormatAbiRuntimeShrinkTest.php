<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on sprintf/printf/number_format ABI shells from Builtin\Type (#32921).
 *
 * NestedJIT/AOT bridges stay StringFormat / SprintfJitHelper / NumberFormatRuntime.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint sprintf.1 / number_format.1 (#31894 / #32122).
 */
final class TypeDeadFormatAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_sprintf',
            '__compiler_printf',
            '__compiler_number_format',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnFormatAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32921', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32921)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32921)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_utf8_strlen'", $type);
        $this->assertStringContainsString('StringFormat::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresFormatAbisModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFormat.php');
        $this->assertStringContainsString('#32921', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $owner, "{$sym} must remain owned by StringFormat (#32921)");
        }
        $this->assertFileExists(__DIR__.'/../../ext/standard/SprintfJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitNumberFormat.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSprintf.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPrintf.php');
    }

    public function testTypeInitializeStillEnsureLinksFormatRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringFormat::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForFormatAbis(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/formatted_print.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/formatted_print.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/sprintf.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/sprintf.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/number_format.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/number_format.c');
    }
}
