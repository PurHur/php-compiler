<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * sodium NestedJIT via JitVmHelperLink::ensureCompiled (#23519 / peer #23498).
 */
final class SodiumRuntimeShrinkTest extends TestCase
{
    public function testStringSodiumUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSodium.php');
        $this->assertStringContainsString('SodiumJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testSpineBundleIncludesSodiumJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SodiumJitHelper.php', $spine);
        $this->assertStringContainsString('StringSodium.php', $spine);
    }

    /** #26871 — AOT/JIT sodium_bin2hex reuses StringBin2hex (no stub LogicException). */
    public function testSodiumBin2hexCallUsesStringBin2hex(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sodium/sodium_bin2hex.php');
        $this->assertStringContainsString('StringBin2hex::ensureLinked', $source);
        $this->assertStringContainsString('__compiler_bin2hex', $source);
        $this->assertStringContainsString('lowerTrimFamilyString', $source);
        $this->assertStringNotContainsString('JIT is not supported', $source);
    }

    /** #27318 — AOT/JIT xchacha AEAD via thin libsodium LLVM (no NestedJIT FFI). */
    public function testSodiumAeadXchachaCallUsesStringSodiumAead(): void
    {
        $encrypt = (string) file_get_contents(
            __DIR__.'/../../ext/sodium/sodium_crypto_aead_xchacha20poly1305_ietf_encrypt.php'
        );
        $decrypt = (string) file_get_contents(
            __DIR__.'/../../ext/sodium/sodium_crypto_aead_xchacha20poly1305_ietf_decrypt.php'
        );
        $this->assertStringContainsString('JitSodium::invokeAeadXchachaIetfEncrypt', $encrypt);
        $this->assertStringContainsString('JitSodium::invokeAeadXchachaIetfDecrypt', $decrypt);
        $this->assertStringNotContainsString('JIT is not supported', $encrypt);
        $this->assertStringNotContainsString('JIT is not supported', $decrypt);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSodiumAead.php');
        $this->assertStringContainsString('crypto_aead_xchacha20poly1305_ietf_encrypt', $runtime);
        $this->assertStringContainsString('libsodium', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringNotContainsString('\\FFI::', $runtime);

        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringContainsString('libsodium.so.23', $linker);
        $this->assertStringContainsString('sodiumRuntimeAvailable', $linker);

        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StringSodiumAead.php', $spine);
    }

    /** #27292 — AOT/JIT sodium_crypto_generichash via thin libsodium (peer AEAD #27318). */
    public function testSodiumGenerichashCallUsesStringSodiumGenerichash(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/sodium/SodiumGenerichashFunction.php');
        $this->assertStringContainsString('JitSodium::invokeGenerichash', $source);
        $this->assertStringContainsString('lowerZparamStr', $source);
        $this->assertStringNotContainsString('JIT is not supported', $source);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSodiumGenerichash.php');
        $this->assertStringContainsString('crypto_generichash', $bridge);
        $this->assertStringContainsString('libsodium', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringNotContainsString('\\FFI::', $bridge);

        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StringSodiumGenerichash.php', $spine);
    }
}
