<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** json_decode() JIT routes through JsonDecodeJitHelper PHP not StringJsonDecodeJit LLVM (#9359, #13228). */
final class JsonDecodeRuntimeShrinkTest extends TestCase
{
    public function testStringJsonDecodeUsesJitHelperNotLlvmWalker(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('JsonDecodeJitHelper', $source);
        $this->assertStringNotContainsString('StringJsonDecodeJit', $source);
        $this->assertLessThan(250, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecodeJit.php');
    }

    public function testJsonDecodeJitHelperDelegatesToVmJson(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JsonDecodeJitHelper.php');
        $this->assertStringContainsString('VmJsonFormat::decode', $source);
        $this->assertStringContainsString('VmJsonScanner::validate', $source);
        $this->assertStringContainsString('VmJson::importDecoded', $source);
        $this->assertStringContainsString('VmJson::lastError', $source);
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonDecode.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
    }
}
