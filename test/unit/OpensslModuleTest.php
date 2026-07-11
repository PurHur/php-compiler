<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * openssl extension module skeleton registration (issue #7000).
 *
 * @group openssl_module_skeleton
 */
final class OpensslModuleTest extends TestCase
{
    public function test_openssl_module_skeleton_functions_and_constants(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['openssl_encrypt', 'openssl_decrypt', 'openssl_sign', 'openssl_verify', 'openssl_get_cipher_methods', 'openssl_get_md_methods', 'openssl_pkey_new', 'openssl_pkey_derive', 'openssl_cipher_iv_length', 'openssl_cipher_key_length', 'openssl_digest', 'openssl_pbkdf2'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('openssl_encrypt');
echo (int) function_exists('openssl_decrypt');
echo (int) function_exists('openssl_sign');
echo (int) function_exists('openssl_verify');
echo (int) function_exists('openssl_get_cipher_methods');
echo (int) function_exists('openssl_get_md_methods');
echo (int) function_exists('openssl_pkey_new');
echo (int) function_exists('openssl_pkey_derive');
echo (int) function_exists('openssl_cipher_iv_length');
echo (int) function_exists('openssl_cipher_key_length');
echo (int) function_exists('openssl_digest');
echo (int) function_exists('openssl_pbkdf2');
echo (int) defined('OPENSSL_RAW_DATA');
echo OPENSSL_RAW_DATA;
echo (int) defined('OPENSSL_ZERO_PADDING');
echo OPENSSL_ZERO_PADDING;
echo (int) defined('OPENSSL_ALGO_SHA256');
echo OPENSSL_ALGO_SHA256;
echo (int) extension_loaded('openssl');
echo (int) class_exists('OpenSSLCertificate', false);
PHP;
        $block = $runtime->parseAndCompile($code, 'openssl_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11111111111111121711', ob_get_clean());
    }

    public function test_openssl_cipher_key_length_aes_256_cbc(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\openssl\openssl_cipher_key_length();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = (static function () use ($runtime) {
            $v = new \PHPCompiler\VM\Variable();
            $v->string('aes-256-cbc');

            return $v;
        })();
        $frame->returnVar = new \PHPCompiler\VM\Variable();
        $fn->execute($frame);
        self::assertSame(\PHPCompiler\VM\Variable::TYPE_INTEGER, $frame->returnVar->type);
        self::assertSame(32, $frame->returnVar->toInt());
    }

    public function test_openssl_cipher_iv_length_aes_256_cbc(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\openssl\openssl_cipher_iv_length();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = (static function () use ($runtime) {
            $v = new \PHPCompiler\VM\Variable();
            $v->string('aes-256-cbc');

            return $v;
        })();
        $frame->returnVar = new \PHPCompiler\VM\Variable();
        $fn->execute($frame);
        self::assertSame(\PHPCompiler\VM\Variable::TYPE_INTEGER, $frame->returnVar->type);
        self::assertSame(16, $frame->returnVar->toInt());
    }

    public function test_openssl_encrypt_stub_throws_logic_exception(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\openssl\openssl_encrypt();
        $frame = $fn->getFrame($runtime->vmContext);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('openssl_encrypt() is not implemented in this compiler build (issue #3324)');
        $fn->execute($frame);
    }

    public function test_openssl_sign_verify_roundtrip_when_ffi_available(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslSignNative::available()) {
            self::markTestSkipped('libcrypto FFI unavailable');
        }

        $privateKey = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIBVAIBADANBgkqhkiG9w0BAQEFAASCAT4wggE6AgEAAkEAs/agkMDOJDS7Udfu
b2zoYYZdjXjmjEGVAKQ0jcNsjzx8UizZZdezyq9Cb/a1Z8epPFm0KPXWO/DrfaO/
pJdN0wIDAQABAkEAqAYbsisiDLHjNy35o7U2Xl/6lu0LrGZK/TdTDg0pHa2Tg2bU
sRDsUL7mG+Sg7nXUkGQnMOc6PjHwRlF1v5i6EQIhAO6cRDOKu4OzmpsFpDz8RcAb
fKcHtRGQoqNiHGkjOrd7AiEAwRQwNwDjClD+3IMkLHR/1d2MSRunQ/mYf+SHs51Y
R4kCIA4uXWNO0HwwVXT3Ld6uA5s6RvtKWvmTRgc90oBxJpE3AiAXGnVSf5arS1nT
xRV1BFOvoZ0Bun9fUOSAmTXrti40EQIgd7h1Ch05DM18TUSosFD/valTgZyBNqO5
YQqYKeRM/Yk=
-----END PRIVATE KEY-----
PEM;
        $publicKey = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBALP2oJDAziQ0u1HX7m9s6GGGXY145oxB
lQCkNI3DbI88fFIs2WXXs8qvQm/2tWfHqTxZtCj11jvw632jv6SXTdMCAwEAAQ==
-----END PUBLIC KEY-----
PEM;

        $runtime = new Runtime();
        $signFn = new \PHPCompiler\ext\openssl\openssl_sign();
        $signFrame = $signFn->getFrame($runtime->vmContext);
        $dataVar = new \PHPCompiler\VM\Variable();
        $dataVar->string('probe');
        $sigVar = new \PHPCompiler\VM\Variable();
        $keyVar = new \PHPCompiler\VM\Variable();
        $keyVar->string($privateKey);
        $algoVar = new \PHPCompiler\VM\Variable();
        $algoVar->int(\PHPCompiler\ext\openssl\OpensslConstants::OPENSSL_ALGO_SHA256);
        $signFrame->calledArgs = [$dataVar, $sigVar, $keyVar, $algoVar];
        $signFrame->returnVar = new \PHPCompiler\VM\Variable();
        $signFn->execute($signFrame);
        self::assertTrue($signFrame->returnVar->toBool());
        self::assertSame(\PHPCompiler\VM\Variable::TYPE_STRING, $sigVar->type);

        $verifyFn = new \PHPCompiler\ext\openssl\openssl_verify();
        $verifyFrame = $verifyFn->getFrame($runtime->vmContext);
        $pubVar = new \PHPCompiler\VM\Variable();
        $pubVar->string($publicKey);
        $verifyFrame->calledArgs = [$dataVar, $sigVar, $pubVar, $algoVar];
        $verifyFrame->returnVar = new \PHPCompiler\VM\Variable();
        $verifyFn->execute($verifyFrame);
        self::assertSame(1, $verifyFrame->returnVar->toInt());
    }

    public function test_openssl_pkey_derive_ecdh_when_ffi_available(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslPkeyDeriveNative::available()) {
            self::markTestSkipped('libcrypto FFI unavailable');
        }

        $alicePrivate = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEINECSnzz+DNYkIONFEHxZYkuDmKPGSJi6YLFh/S6KcazoAoGCCqGSM49
AwEHoUQDQgAEBIfnBb99kI2pwkDZEJvzby+Kx3QLSW5Q3vk1RgH78kLbLeR/r5E2
FoQhKi3UU7e5wD9eUgQkgPTSVG62qLg43A==
-----END EC PRIVATE KEY-----
PEM;
        $bobPublic = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEycQC9/n88uWz5TfRBpVbiWAfOn5A
TiLVZcDrF6mzgco+dPRy/rk/Bu6oqH3EU0RTqD8y4tlWdRl2u2GCW37RBg==
-----END PUBLIC KEY-----
PEM;

        $runtime = new Runtime();
        $deriveFn = new \PHPCompiler\ext\openssl\openssl_pkey_derive();
        $frame = $deriveFn->getFrame($runtime->vmContext);
        $pubVar = new \PHPCompiler\VM\Variable();
        $pubVar->string($bobPublic);
        $privVar = new \PHPCompiler\VM\Variable();
        $privVar->string($alicePrivate);
        $frame->calledArgs = [$pubVar, $privVar];
        $frame->returnVar = new \PHPCompiler\VM\Variable();
        $deriveFn->execute($frame);

        self::assertSame(\PHPCompiler\VM\Variable::TYPE_STRING, $frame->returnVar->type);
        self::assertSame(32, \strlen($frame->returnVar->toString()));
        self::assertSame(
            'a89ceecb80ea0e5b66a50bb93ca3bb8f9a490c67897cc56734d28061a086d6b5',
            \bin2hex($frame->returnVar->toString())
        );
    }
}
