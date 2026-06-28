<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9180 / #13311: serialize() LLVM helpers route through SerializeJitHelper PHP.
 *
 * @group aot-lint
 */
final class StringSerializeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRoutesSerializeThroughPhpHelper(): void
    {
        $runtime = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('SerializeJitHelper', $runtime);
        $this->assertStringNotContainsString('StringSerializeJit', $runtime);
        $this->assertLessThan(160, \substr_count($runtime, "\n"), 'StringSerialize must be a thin bridge (#13311)');

        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringSerializeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringSerializeDoubleJit.php');

        $helper = (string) \file_get_contents(__DIR__.'/../../../ext/standard/SerializeJitHelper.php');
        $this->assertStringContainsString('VmSerialize::serializeValue', $helper);
        $this->assertStringContainsString('encodeHashtable', $helper);
    }
}
