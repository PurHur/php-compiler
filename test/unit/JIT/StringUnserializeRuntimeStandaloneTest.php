<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5991: unserialize() LLVM helpers replace phpc_unserialize.c.
 *
 * @group aot-lint
 */
final class StringUnserializeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesUnserializeC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_unserialize.c');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUnserializeJit.php');
        $this->assertStringContainsString('__compiler_unserialize', $jit);
        $this->assertStringContainsString('phpc_session_decode_payload', $jit);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_unserialize.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('StringUnserializeJit', $runtime);
        $this->assertStringNotContainsString('phpc_unserialize.c', $runtime);
    }
}
