<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\sodium\SodiumExtensionPolicy;
use PHPCompiler\ext\sodium\VmSodium;
use PHPUnit\Framework\TestCase;

/**
 * AEGIS AEAD registration follows libsodium symbols (#28086 / #20518), not host Zend alone.
 */
final class SodiumAegisGateTest extends TestCase
{
    public function testPolicyGatesOnVmSodiumAegisAvailability(): void
    {
        $policy = (string) file_get_contents(__DIR__.'/../../ext/sodium/SodiumExtensionPolicy.php');
        $this->assertStringContainsString('VmSodium::aeadAegis128lAvailable()', $policy);
        $this->assertStringContainsString('VmSodium::aeadAegis256Available()', $policy);

        $module = (string) file_get_contents(__DIR__.'/../../ext/sodium/Module.php');
        $this->assertStringContainsString('advertisesAegis128l()', $module);
        $this->assertStringContainsString('advertisesAegis256()', $module);
        $this->assertStringContainsString('sodium_crypto_aead_aegis128l_encrypt', $module);
        $this->assertStringContainsString('sodium_crypto_aead_aegis256_encrypt', $module);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/sodium/VmSodium.php');
        $this->assertStringContainsString('function ffiAegis()', $vm);
        $this->assertStringContainsString('PHP_COMPILER_LIBSODIUM_SO', $vm);
        $this->assertStringContainsString('crypto_aead_aegis128l_encrypt', $vm);
        $this->assertStringContainsString('crypto_aead_aegis256_encrypt', $vm);
    }

    public function testNoPhantomAegisWhenUnavailable(): void
    {
        if (!extension_loaded('sodium') && !VmSodium::available()) {
            $this->markTestSkipped('sodium unavailable');
        }

        $aegis = SodiumExtensionPolicy::advertisesAegis128l()
            || SodiumExtensionPolicy::advertisesAegis256();
        if ($aegis) {
            $this->assertTrue(function_exists('sodium_crypto_aead_aegis128l_encrypt'));
            $this->assertTrue(function_exists('sodium_crypto_aead_aegis256_encrypt'));
            $this->assertTrue(defined('SODIUM_CRYPTO_AEAD_AEGIS128L_KEYBYTES'));
            $this->assertTrue(defined('SODIUM_CRYPTO_AEAD_AEGIS256_KEYBYTES'));

            return;
        }

        $this->assertFalse(function_exists('sodium_crypto_aead_aegis128l_encrypt'));
        $this->assertFalse(function_exists('sodium_crypto_aead_aegis128l_decrypt'));
        $this->assertFalse(function_exists('sodium_crypto_aead_aegis128l_keygen'));
        $this->assertFalse(function_exists('sodium_crypto_aead_aegis256_encrypt'));
        $this->assertFalse(function_exists('sodium_crypto_aead_aegis256_decrypt'));
        $this->assertFalse(function_exists('sodium_crypto_aead_aegis256_keygen'));
        $this->assertFalse(defined('SODIUM_CRYPTO_AEAD_AEGIS128L_KEYBYTES'));
        $this->assertFalse(defined('SODIUM_CRYPTO_AEAD_AEGIS256_KEYBYTES'));
        // Control: core sodium AEAD still advertised when extension is.
        if (SodiumExtensionPolicy::advertisesExtension()) {
            $this->assertTrue(function_exists('sodium_crypto_aead_aes256gcm_encrypt'));
        }
    }
}
