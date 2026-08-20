<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on pack/unpack ABI shells from Builtin\Type (#32936).
 *
 * NestedJIT/AOT bridges stay StringPack / StringUnpack / PackJitHelper / UnpackJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint pack.1 / unpack.1 (#31894 / #32122).
 */
final class TypeDeadPackAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_pack',
            '__compiler_unpack',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnPackAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32936', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32936)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32936)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_utf8_strlen'", $type);
        $this->assertStringContainsString('StringPack::ensureLinked', $type);
        $this->assertStringContainsString('StringUnpack::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresPackAbisModuleLocally(): void
    {
        $pack = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPack.php');
        $unpack = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnpack.php');
        $this->assertStringContainsString('#32936', $pack);
        $this->assertStringContainsString('#32936', $unpack);
        $this->assertStringContainsString('getNamedFunction', $pack);
        $this->assertStringContainsString('getNamedFunction', $unpack);
        $this->assertStringContainsString('__compiler_pack', $pack);
        $this->assertStringContainsString('__compiler_unpack', $unpack);
        $this->assertFileExists(__DIR__.'/../../ext/standard/PackJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/UnpackJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPack.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitUnpack.php');
    }

    public function testTypeInitializeStillEnsureLinksPackRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringPack::ensureLinked($this->context)', $type);
        $this->assertStringContainsString('StringUnpack::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForPackAbis(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/pack.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/pack.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/unpack.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/unpack.c');
    }
}
