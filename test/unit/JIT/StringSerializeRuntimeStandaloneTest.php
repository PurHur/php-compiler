<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9180 / #13311 / #27030: serialize() LLVM helpers route through NestedJIT PHP.
 *
 * @group aot-lint
 */
final class StringSerializeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRoutesSerializeThroughNestedJitHelper(): void
    {
        $runtime = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('SerializeNestedJitHelper', $runtime);
        $this->assertStringContainsString('SerializeObjectNestedJitHelper', $runtime);
        $this->assertStringNotContainsString('StringSerializeJit', $runtime);

        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringSerializeJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringSerializeDoubleJit.php');

        $helper = (string) \file_get_contents(__DIR__.'/../../../ext/standard/SerializeNestedJitHelper.php');
        $this->assertStringContainsString('encodeHashtable', $helper);
        $this->assertStringContainsString('exportKeyValuePairs', $helper);
        $this->assertStringNotContainsString('VmSerialize::', $helper);
        $this->assertStringNotContainsString('Superglobals', $helper);
    }
}
