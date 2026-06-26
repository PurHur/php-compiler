<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** json_decode() JIT routes through JsonDecodeJitHelper PHP not StringJsonDecodeJit LLVM (#9359). */
final class JsonDecodeRuntimeShrinkTest extends TestCase
{
    public function testStringJsonDecodeUsesJitHelperNotLlvmWalker(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('JsonDecodeJitHelper', $source);
        $this->assertStringContainsString('StringJsonDecodeJit', $source);
        $this->assertStringNotContainsString('StringJsonDecodeJit::implement', $source);
        $this->assertLessThan(250, \substr_count($source, "\n") + 1);
    }

    public function testJsonDecodeJitHelperDelegatesToVmJson(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JsonDecodeJitHelper.php');
        $this->assertStringContainsString('VmJsonFormat::decode', $source);
        $this->assertStringContainsString('VmJsonScanner::validate', $source);
        $this->assertStringContainsString('VmJson::importDecoded', $source);
        $this->assertStringContainsString('VmJson::lastError', $source);
    }

    public function testStringJsonDecodeJitRetainsStandaloneLlvm(): void
    {
        $jitMonolith = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecodeJit.php');
        $this->assertGreaterThan(1000, \substr_count($jitMonolith, "\n"), 'StringJsonDecodeJit retains standalone LLVM');
        $this->assertStringContainsString('emitCompilerJsonDecode', $jitMonolith);
    }
}
