<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9163: unserialize() LLVM helpers route through UnserializeJitHelper PHP (#5991).
 *
 * @group aot-lint
 */
final class StringUnserializeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesUnserializeC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_unserialize.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_unserialize.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('UnserializeJitHelper', $runtime);
        $this->assertStringContainsString('StringUnserializeJit', $runtime);
        $this->assertStringNotContainsString('phpc_unserialize.c', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../../ext/standard/UnserializeJitHelper.php');
        $this->assertStringContainsString('VmUnserializeFormat::decodePayload', $helper);
    }
}
