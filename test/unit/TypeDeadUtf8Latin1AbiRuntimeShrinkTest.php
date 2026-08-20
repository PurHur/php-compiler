<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on utf8_encode/utf8_decode ABI shells from Builtin\Type (#32879).
 *
 * NestedJIT/AOT bridge stays StringUtf8Latin1 → Utf8Latin1JitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so
 * leftover Type empty decls cannot mint utf8_encode.1 (#31894 / #32122).
 *
 * Leave convert_uuencode Type rows alone (sentinel for peer shrink tests).
 */
final class TypeDeadUtf8Latin1AbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_utf8_encode',
            '__compiler_utf8_decode',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnUtf8Latin1Abis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32879', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32879)"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/registerFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-register {$sym} (#32879)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_convert_uuencode'", $type);
        $this->assertStringContainsString('StringUtf8Latin1::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresUtf8Latin1AbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Latin1.php');
        $this->assertStringContainsString('#32879', $svc);
        $this->assertStringContainsString('getNamedFunction(', $svc);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc);
        }
    }

    public function testTypeInitializeStillEnsureLinksStringUtf8Latin1(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringUtf8Latin1::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/Utf8Latin1JitHelper.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringUtf8Latin1.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/utf8_encode.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/utf8_decode.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/utf8_latin1.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_utf8.c'
        );
    }
}
