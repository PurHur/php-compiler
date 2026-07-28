<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6202 / #13228: json_decode() LLVM helpers replace phpc_json_decode.c.
 *
 * @group aot-lint
 */
final class StringJsonDecodeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesJsonDecodeCAndLlvmMonolith(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_json_decode.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringJsonDecodeJit.php');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_json_decode.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('JsonDecodeJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $runtime);
        $this->assertStringNotContainsString('StringJsonDecodeInventoryStubs', $runtime);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $runtime);
        $this->assertStringNotContainsString('StringJsonDecodeJit', $runtime);
        $this->assertStringNotContainsString('phpc_json_decode.c', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../../ext/standard/JsonDecodeJitHelper.php');
        $this->assertStringContainsString('function decodeAssocArray(string $payload): HashTable', $helper);
        $this->assertStringContainsString('function decodeInt(string $payload): int', $helper);
        $this->assertFileExists(__DIR__.'/../../../ext/standard/JsonValidateJitHelper.php');
    }

    public function testJsonDecodeJitCompileTimeBoolUsesLlvmIsAConstantInt(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../ext/standard/json_decode.php');
        $this->assertStringContainsString('LLVMIsAConstantInt($var->value->value)', $source);
        $this->assertStringNotContainsString('$var->value->isAConstantInt()', $source);
        // ConstFetch true/false is a Load — honor compileTimeConstantName (#24137).
        $this->assertStringContainsString('compileTimeConstantName', $source);
    }

    public function testJsonDecodeJitCompileTimePathDoesNotCallHostJsonDecode(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../ext/standard/json_decode.php');
        $this->assertStringNotContainsString('\\json_decode', $source);
        $this->assertStringContainsString('VmJsonFormat::decode', $source);
    }
}
