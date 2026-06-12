<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VmOpenssl introspection without host ext/openssl delegation (#6228). */
final class VmOpensslRuntimeShrinkTest extends TestCase
{
    public function testVmOpensslDoesNotDelegateToHostOpenssl(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/VmOpenssl.php');
        $this->assertStringContainsString('OpensslCipherRegistry::cipherIvLength', $source);
        $this->assertStringContainsString('OpensslCipherRegistry::cipherKeyLength', $source);
        $this->assertStringContainsString('function cipher_iv_length', $source);
        $this->assertStringContainsString('function cipher_key_length', $source);
        $this->assertStringNotContainsString("function_exists('openssl_cipher_iv_length')", $source);
        $this->assertStringNotContainsString("function_exists('openssl_cipher_key_length')", $source);
        $this->assertStringNotContainsString('\\openssl_cipher_iv_length(', $source);
        $this->assertStringNotContainsString('\\openssl_cipher_key_length(', $source);
        $this->assertStringNotContainsString('\\openssl_digest(', $source);
    }

    public function testCipherIvLengthNativeLookup(): void
    {
        $this->assertSame(16, \PHPCompiler\ext\openssl\VmOpenssl::cipher_iv_length('aes-256-cbc'));
        $length = @\PHPCompiler\ext\openssl\VmOpenssl::cipher_iv_length('not-a-real-cipher-method');
        $this->assertFalse($length);
    }

    public function testCipherKeyLengthNativeLookup(): void
    {
        $this->assertSame(32, \PHPCompiler\ext\openssl\VmOpenssl::cipher_key_length('aes-256-cbc'));
        $length = @\PHPCompiler\ext\openssl\VmOpenssl::cipher_key_length('not-a-real-cipher-method');
        $this->assertFalse($length);
    }

    public function testDigestSha256MatchesZendHex(): void
    {
        $digest = \PHPCompiler\ext\openssl\VmOpenssl::digest('data', 'sha256');
        $this->assertSame(
            '3a6eb0790f39ac87c94f3856b2dd2c5d110e6811602261a9a923d3bb23adc8b7',
            $digest
        );
    }
}
