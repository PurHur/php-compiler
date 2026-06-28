<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** unserialize() JIT routes through UnserializeJitHelper PHP not StringUnserializeJit LLVM (#9163, #13312). */
final class UnserializeRuntimeShrinkTest extends TestCase
{
    public function testStringUnserializeUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('UnserializeJitHelper', $source);
        $this->assertStringNotContainsString('StringUnserializeJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertLessThan(230, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringUnserializeJit.php');
    }

    public function testUnserializeJitHelperDelegatesToVmUnserializeFormat(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UnserializeJitHelper.php');
        $this->assertStringContainsString('VmUnserializeFormat::decodeToVariableWithContext', $source);
        $this->assertStringContainsString('VmSessionSerializer::decodeWireHashTable', $source);
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
    }
}
