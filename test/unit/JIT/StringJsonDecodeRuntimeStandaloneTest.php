<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6202: json_decode() LLVM helpers replace phpc_json_decode.c.
 *
 * @group aot-lint
 */
final class StringJsonDecodeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesJsonDecodeC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_json_decode.c');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringJsonDecodeJit.php');
        $this->assertStringContainsString('__compiler_json_decode', $jit);
        $this->assertStringContainsString('__compiler_json_validate', $jit);
        $this->assertStringContainsString('__phpc_json_parse_post_body', $jit);
        $this->assertStringContainsString('__compiler_json_last_error', $jit);
        $this->assertStringContainsString('__compiler_json_last_error_msg', $jit);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_json_decode.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('StringJsonDecodeJit', $runtime);
        $this->assertStringNotContainsString('phpc_json_decode.c', $runtime);
    }

    public function testJsonDecodeJitCompileTimeBoolUsesLlvmIsAConstantInt(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../ext/standard/json_decode.php');
        $this->assertStringContainsString('LLVMIsAConstantInt($var->value->value)', $source);
        $this->assertStringNotContainsString('$var->value->isAConstantInt()', $source);
    }

    public function testJsonDecodeJitCompileTimePathDoesNotCallHostJsonDecode(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../ext/standard/json_decode.php');
        $this->assertStringNotContainsString('\\json_decode', $source);
        $this->assertStringContainsString('VmJsonFormat::decode', $source);
    }
}
