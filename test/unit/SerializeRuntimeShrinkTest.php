<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** serialize() JIT routes through SerializeJitHelper PHP not StringSerializeJit LLVM (#9180, #13311). */
final class SerializeRuntimeShrinkTest extends TestCase
{
    public function testStringSerializeUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('SerializeJitHelper', $source);
        $this->assertStringNotContainsString('StringSerializeJit', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertLessThan(210, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringSerializeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringSerializeDoubleJit.php');
    }

    public function testSerializeJitHelperDelegatesToVmSerialize(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SerializeJitHelper.php');
        $this->assertStringContainsString('VmSerialize::serializeValue', $source);
        $this->assertStringContainsString('encodeHashtable', $source);
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringContainsString('implementDeferredInventoryStubs', $source);
    }
}
