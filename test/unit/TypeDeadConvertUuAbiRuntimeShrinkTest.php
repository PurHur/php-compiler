<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on convert_uuencode/uudecode ABI shells from Builtin\Type (#32982).
 *
 * NestedJIT/AOT bridge stays StringConvertUu + ConvertUuJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint convert_uuencode.1 (#31894 / #32122).
 */
final class TypeDeadConvertUuAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_convert_uuencode',
            '__compiler_convert_uudecode',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnConvertUuAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32982', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32982)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32982)"
            );
        }
        $this->assertStringContainsString('LibcExtern::ensureExitAbort', $type);
        $this->assertStringContainsString('StringConvertUu::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresConvertUuAbisModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringConvertUu.php');
        $this->assertStringContainsString('#32982', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $owner);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $owner, "{$sym} must remain owned by StringConvertUu (#32982)");
        }
        $this->assertFileExists(__DIR__.'/../../ext/standard/ConvertUuJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/convert_uuencode.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/convert_uudecode.php');
    }

    public function testTypeInitializeStillEnsureLinksConvertUuRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringConvertUu::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForConvertUuAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/convert_uu.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/convert_uu.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/uuencode.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/uuencode.c');
    }
}
