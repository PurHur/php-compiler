<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Dead HashContextCopyLlvm / HashContextFinalLlvm deleted after JitHashContext inline lowering (#19587).
 *
 * hash_copy() / hash_final() route through {@see \PHPCompiler\ext\hash\JitHashContext}; the
 * unused `__compiler_hash_context_*` LLVM bridges had zero callers.
 */
final class HashContextDeadLlvmShrinkTest extends TestCase
{
    public function testHashContextCopyAndFinalLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/HashContextCopyLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/HashContextFinalLlvm.php');
    }

    public function testSpineOmitsDeadHashContextLlvmRequires(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('HashContextCopyLlvm.php', $spine);
        $this->assertStringNotContainsString('HashContextFinalLlvm.php', $spine);
        $this->assertStringContainsString('HashContextEmbedBridge.php', $spine);
        $this->assertStringContainsString('ext/hash/JitHashContext.php', $spine);
    }

    public function testJitHashContextOwnsCopyAndFinalLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/hash/JitHashContext.php');
        $this->assertStringContainsString('function copyLowering(', $source);
        $this->assertStringContainsString('function finalLowering(', $source);
        $this->assertStringNotContainsString('HashContextCopyLlvm', $source);
        $this->assertStringNotContainsString('HashContextFinalLlvm', $source);
    }
}
