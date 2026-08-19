<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Dead HashContextCopyLlvm / HashContextFinalLlvm deleted after JitHashContext inline lowering (#19587).
 *
 * hash_copy() / hash_final() route through {@see \PHPCompiler\ext\hash\JitHashContext}; the
 * unused `__compiler_hash_context_*` LLVM bridges had zero callers.
 *
 * Thin standalone AOT gates on {@see \PHPCompiler\JIT\Context::isThinStandaloneAotMain()} —
 * no {@see \PHPCompiler\JIT\UserScriptAotDeferNestedJit} (#20200 / #20178 shape).
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
        $this->assertStringContainsString("'hash_update_file' => self::updateFile(", $source);
        $this->assertStringContainsString('function updateFile(', $source);
        $this->assertStringNotContainsString('HashContextCopyLlvm', $source);
        $this->assertStringNotContainsString('HashContextFinalLlvm', $source);
    }

    public function testHashFinalThinPathUsesIsThinStandaloneAotMain(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/hash/JitHashContext.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('finalLoweringStandaloneAot', $source);
        $this->assertStringContainsString('JitHash::hash', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    /**
     * Undeclared HashContext props auto-define as TYPE_STRING; HMAC int64 store then fails (#27264).
     */
    public function testObjectDeclaresHashContextKeyAndHmacSlots(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString("defineProperty(\$id, '__hcKey', Variable::TYPE_STRING)", $source);
        $this->assertStringContainsString("defineProperty(\$id, '__hcHmac', Variable::TYPE_NATIVE_LONG)", $source);
        $this->assertMatchesRegularExpression(
            "/'__hcid' === \\\$lcName \|\| '__hchmac' === \\\$lcName/",
            $source
        );
        $this->assertStringContainsString("'__hckey' === \$lcName", $source);
    }
}
