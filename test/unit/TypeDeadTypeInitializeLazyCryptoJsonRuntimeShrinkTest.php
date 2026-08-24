<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Type::initialize always-on openssl/hash/json/libcrypt/password/random_bytes
 * ensureLinked (#34332 / peer #34327).
 *
 * Call-site Jit* owners link lazily (getNamedFunction first) so hello-world and
 * other scripts that never touch these builtins skip NestedJIT on the full load
 * path (#32122 .1 mint class).
 */
final class TypeDeadTypeInitializeLazyCryptoJsonRuntimeShrinkTest extends TestCase
{
    public function testTypeInitializeDropsEagerCryptoJsonPasswordRandomEnsureLinked(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#34332', $type);
        foreach ([
            'LibcryptRuntime::ensureLinked($this->context)',
            'PasswordRandomBytesRuntime::ensureLinked($this->context)',
            'StringRandomBytes::ensureLinked($this->context)',
            'PasswordCryptoRuntime::ensureLinked($this->context)',
            'StringHashCrypto::ensureLinked($this->context)',
            'OpensslEncryptRuntime::ensureLinked($this->context)',
            'OpensslSignRuntime::ensureLinked($this->context)',
            'OpensslDigestRuntime::ensureLinked($this->context)',
            'OpensslPbkdf2Runtime::ensureLinked($this->context)',
            'StringHashEquals::ensureLinked($this->context)',
            'StringHashHmacAlgos::ensureLinked($this->context)',
            'StringHashAlgos::ensureLinked($this->context)',
            'StringJsonEncode::ensureLinked($this->context)',
            'StringJsonDecode::ensureLinked($this->context)',
        ] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $type,
                'Builtin\\Type::initialize must not eagerly '.$call.' (#34332)'
            );
        }
    }

    public function testCallSitesEnsureLinkBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitLibcrypt.php' => 'LibcryptRuntime::ensureLinked',
            'ext/standard/JitPasswordRandomBytes.php' => 'PasswordRandomBytesRuntime::ensureLinked',
            'ext/standard/JitRandomBytes.php' => 'StringRandomBytes::ensureLinked',
            'ext/standard/JitPassword.php' => 'StringPasswordCrypto::ensureLinked',
            'ext/standard/JitHash.php' => 'StringHashCrypto::ensureLinked',
            'lib/JIT/Builtin/OpensslEncryptCrypto.php' => 'OpensslEncryptRuntime::ensureLinked',
            'lib/JIT/Builtin/OpensslSignCrypto.php' => 'OpensslSignRuntime::ensureLinked',
            'lib/JIT/Builtin/OpensslDigestCrypto.php' => 'OpensslDigestRuntime::ensureLinked',
            'ext/openssl/openssl_pbkdf2.php' => 'OpensslPbkdf2Runtime::ensureLinked',
            'ext/standard/JitJsonEncode.php' => 'StringJsonEncode::ensureLinked',
            'ext/standard/JitJsonDecode.php' => 'StringJsonDecode::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensureLinked before lookup (#34332)');
        }
    }

    public function testNoNewRuntimeCForLazyCryptoJsonAbis(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_openssl_encrypt.c',
            'phpc_hash_crypto.c',
            'phpc_json.c',
            'phpc_libcrypt.c',
            'phpc_password_random_bytes.c',
            'phpc_random_bytes.c',
        ] as $basename) {
            $this->assertFileDoesNotExist($runtimeDir.'/'.$basename, $basename.' must stay absent (#34332)');
        }
    }
}
