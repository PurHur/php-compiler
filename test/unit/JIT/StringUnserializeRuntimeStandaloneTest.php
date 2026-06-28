<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9163 / #13312: unserialize() LLVM helpers route through UnserializeJitHelper PHP.
 *
 * @group aot-lint
 */
final class StringUnserializeRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesUnserializeCAndLlvmMonolith(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_unserialize.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringUnserializeJit.php');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_unserialize.c', $linker);
        $runtime = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringUnserialize.php');
        $this->assertStringContainsString('UnserializeJitHelper', $runtime);
        $this->assertStringNotContainsString('StringUnserializeJit', $runtime);
        $this->assertStringNotContainsString('phpc_unserialize.c', $runtime);
        $helper = (string) file_get_contents(__DIR__.'/../../../ext/standard/UnserializeJitHelper.php');
        $this->assertStringContainsString('VmUnserializeFormat::decodeToVariableWithContext', $helper);
    }
}
