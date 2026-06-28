<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** json_encode() JIT routes through JsonEncodeJitHelper PHP not StringJsonEncodeJit LLVM (#9267, #13239). */
final class JsonEncodeRuntimeShrinkTest extends TestCase
{
    public function testStringJsonEncodeUsesJitHelperNotLlvmWalker(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('JsonEncodeJitHelper', $source);
        $this->assertStringNotContainsString('StringJsonEncodeJit', $source);
        $this->assertStringNotContainsString('implementValue', $source);
        $this->assertStringNotContainsString('implementArray', $source);
        $this->assertLessThan(170, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncodeJit.php');
    }

    public function testJsonEncodeJitHelperDelegatesToVmJson(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JsonEncodeJitHelper.php');
        $this->assertStringContainsString('VmJson::export', $source);
        $this->assertStringContainsString('VmJsonFormat::encodeExported', $source);
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringJsonEncode.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
    }
}
