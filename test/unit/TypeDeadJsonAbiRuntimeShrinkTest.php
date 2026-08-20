<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on json ABI shells from Builtin\Type (#32897).
 *
 * NestedJIT/AOT owners stay StringJsonEncode / StringJsonDecode /
 * JsonEncodeQuoteStringRuntime (getNamedFunction first). Leftover Type empty
 * decls cannot mint json_encode.1 (#31894 / #32122).
 */
final class TypeDeadJsonAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_json_encode_value',
            '__compiler_json_encode_array',
            '__compiler_json_quote_string',
            '__compiler_json_decode',
            '__compiler_json_last_error',
            '__compiler_json_last_error_msg',
            '__compiler_json_set_last_error',
            '__compiler_json_validate',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnJsonAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32897', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32897)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32897)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_convert_uuencode'", $type);
        $this->assertStringContainsString('StringJsonEncode::ensureLinked', $type);
        $this->assertStringContainsString('StringJsonDecode::ensureLinked', $type);
    }

    public function testRuntimeOwnersDeclareJsonAbisModuleLocally(): void
    {
        $encode = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncode.php');
        $decode = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $quote = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JsonEncodeQuoteStringRuntime.php');
        $this->assertStringContainsString('#32897', $encode);
        $this->assertStringContainsString('#32897', $decode);
        $this->assertStringContainsString('#32897', $quote);
        $this->assertStringContainsString('getNamedFunction', $encode);
        $this->assertStringContainsString('getNamedFunction', $decode);
        $this->assertStringContainsString('getNamedFunction', $quote);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $decode);
        foreach (['__compiler_json_encode_value', '__compiler_json_encode_array'] as $sym) {
            $this->assertStringContainsString($sym, $encode, "{$sym} must remain owned by StringJsonEncode (#32897)");
        }
        $this->assertStringContainsString('__compiler_json_quote_string', $quote);
        foreach ([
            '__compiler_json_decode',
            '__compiler_json_validate',
            '__compiler_json_last_error',
            '__compiler_json_last_error_msg',
            '__compiler_json_set_last_error',
        ] as $sym) {
            $this->assertStringContainsString($sym, $decode, "{$sym} must remain owned by StringJsonDecode (#32897)");
        }
    }

    public function testTypeInitializeStillEnsureLinksJsonRuntimes(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringJsonEncode::ensureLinked($this->context)', $type);
        $this->assertStringContainsString('StringJsonDecode::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JsonEncodeNestedJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JsonDecodeJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JsonValidateJitHelper.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/json_encode.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/runtime/json_encode.c'
        );
    }
}
