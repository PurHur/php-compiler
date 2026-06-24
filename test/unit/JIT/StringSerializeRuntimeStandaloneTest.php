<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9180: serialize() LLVM helpers route through SerializeJitHelper PHP.
 *
 * @group aot-lint
 */
final class StringSerializeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRoutesSerializeThroughPhpHelper(): void
    {
        $runtime = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('SerializeJitHelper', $runtime);
        $this->assertStringContainsString('StringSerializeJit', $runtime);
        $this->assertLessThan(160, \substr_count($runtime, "\n"), 'StringSerialize must be a thin bridge (#9180)');

        $jitMonolith = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringSerializeJit.php');
        $this->assertGreaterThan(300, \substr_count($jitMonolith, "\n"), 'StringSerializeJit retains standalone LLVM');

        $helper = (string) \file_get_contents(__DIR__.'/../../../ext/standard/SerializeJitHelper.php');
        $this->assertStringContainsString('VmSerialize::serializeValue', $helper);
        $this->assertStringContainsString('encodeHashtable', $helper);
    }
}
