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

        foreach (['openssl_encrypt', 'openssl_decrypt', 'openssl_sign', 'openssl_verify', 'openssl_seal', 'openssl_open', 'openssl_get_cipher_methods', 'openssl_get_md_methods', 'openssl_pkey_new', 'openssl_pkey_get_private', 'openssl_get_privatekey', 'openssl_pkey_get_public', 'openssl_get_publickey', 'openssl_pkey_get_details', 'openssl_pkey_derive', 'openssl_cipher_iv_length', 'openssl_cipher_key_length', 'openssl_digest', 'openssl_pbkdf2'] as $fn) {
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
echo (int) defined('OPENSSL_PKCS1_PADDING');
echo OPENSSL_PKCS1_PADDING;
echo (int) defined('OPENSSL_NO_PADDING');
echo OPENSSL_NO_PADDING;
echo (int) defined('OPENSSL_PKCS1_OAEP_PADDING');
echo OPENSSL_PKCS1_OAEP_PADDING;
echo (int) defined('OPENSSL_ALGO_SHA256');
echo OPENSSL_ALGO_SHA256;
echo (int) extension_loaded('openssl');
echo (int) class_exists('OpenSSLCertificate', false);
PHP;
        $block = $runtime->parseAndCompile($code, 'openssl_module.php');
        ob_start();
        $runtime->run($block);
        // padding trio from registeredConstants() (#24071): defined+1, defined+3, defined+4
        self::assertSame('11111111111111121113141711', ob_get_clean());
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

    public function test_openssl_encrypt_decrypt_roundtrip_when_ffi_available(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslCipherNative::available()) {
            self::markTestSkipped('libcrypto FFI unavailable');
        }

        $runtime = new Runtime();
        $key = '0123456789abcdef';
        $iv = '0123456789abcdef';

        $encryptFn = new \PHPCompiler\ext\openssl\openssl_encrypt();
        $encryptFrame = $encryptFn->getFrame($runtime->vmContext);
        $dataVar = new \PHPCompiler\VM\Variable();
        $dataVar->string('data');
        $cipherVar = new \PHPCompiler\VM\Variable();
        $cipherVar->string('AES-128-CBC');
        $keyVar = new \PHPCompiler\VM\Variable();
        $keyVar->string($key);
        $optionsVar = new \PHPCompiler\VM\Variable();
        $optionsVar->int(\PHPCompiler\ext\openssl\OpensslConstants::OPENSSL_RAW_DATA);
        $ivVar = new \PHPCompiler\VM\Variable();
        $ivVar->string($iv);
        $encryptFrame->calledArgs = [$dataVar, $cipherVar, $keyVar, $optionsVar, $ivVar];
        $encryptFrame->returnVar = new \PHPCompiler\VM\Variable();
        $encryptFn->execute($encryptFrame);
        self::assertSame(\PHPCompiler\VM\Variable::TYPE_STRING, $encryptFrame->returnVar->type);
        self::assertSame('840a0c413dca6e7dcc58783214795053', \bin2hex($encryptFrame->returnVar->toString()));

        $decryptFn = new \PHPCompiler\ext\openssl\openssl_decrypt();
        $decryptFrame = $decryptFn->getFrame($runtime->vmContext);
        $encVar = new \PHPCompiler\VM\Variable();
        $encVar->string($encryptFrame->returnVar->toString());
        $decryptFrame->calledArgs = [$encVar, $cipherVar, $keyVar, $optionsVar, $ivVar];
        $decryptFrame->returnVar = new \PHPCompiler\VM\Variable();
        $decryptFn->execute($decryptFrame);
        self::assertSame('data', $decryptFrame->returnVar->toString());
    }

    public function test_openssl_encrypt_null_coerces_to_empty_string_when_ffi_available(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslCipherNative::available()) {
            self::markTestSkipped('libcrypto FFI unavailable');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $runtime = new Runtime();
            $encryptFn = new \PHPCompiler\ext\openssl\openssl_encrypt();
            $key = str_repeat('k', 32);
            $iv = str_repeat('i', 16);

            $makeFrame = static function (?\PHPCompiler\VM\Variable $dataVar) use ($runtime, $encryptFn, $key, $iv) {
                $frame = $encryptFn->getFrame($runtime->vmContext);
                $cipherVar = new \PHPCompiler\VM\Variable();
                $cipherVar->string('aes-256-cbc');
                $keyVar = new \PHPCompiler\VM\Variable();
                $keyVar->string($key);
                $optionsVar = new \PHPCompiler\VM\Variable();
                $optionsVar->int(0);
                $ivVar = new \PHPCompiler\VM\Variable();
                $ivVar->string($iv);
                $frame->calledArgs = [$dataVar, $cipherVar, $keyVar, $optionsVar, $ivVar];
                $frame->returnVar = new \PHPCompiler\VM\Variable();

                return $frame;
            };

            $emptyVar = new \PHPCompiler\VM\Variable();
            $emptyVar->string('');
            $emptyFrame = $makeFrame($emptyVar);
            $encryptFn->execute($emptyFrame);

            $nullVar = new \PHPCompiler\VM\Variable();
            $nullVar->null();
            $nullFrame = $makeFrame($nullVar);
            $encryptFn->execute($nullFrame);

            self::assertSame(\PHPCompiler\VM\Variable::TYPE_STRING, $emptyFrame->returnVar->type);
            self::assertSame(\PHPCompiler\VM\Variable::TYPE_STRING, $nullFrame->returnVar->type);
            self::assertNotSame('', $emptyFrame->returnVar->toString());
            self::assertSame($emptyFrame->returnVar->toString(), $nullFrame->returnVar->toString());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_openssl_encrypt_null_soft_coerces_on_forward84_when_ffi_available(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslCipherNative::available()) {
            self::markTestSkipped('libcrypto FFI unavailable');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $encryptFn = new \PHPCompiler\ext\openssl\openssl_encrypt();
            $key = str_repeat('k', 32);
            $iv = str_repeat('i', 16);

            $makeFrame = static function (?\PHPCompiler\VM\Variable $dataVar) use ($runtime, $encryptFn, $key, $iv) {
                $frame = $encryptFn->getFrame($runtime->vmContext);
                $cipherVar = new \PHPCompiler\VM\Variable();
                $cipherVar->string('aes-256-cbc');
                $keyVar = new \PHPCompiler\VM\Variable();
                $keyVar->string($key);
                $optionsVar = new \PHPCompiler\VM\Variable();
                $optionsVar->int(0);
                $ivVar = new \PHPCompiler\VM\Variable();
                $ivVar->string($iv);
                $frame->calledArgs = [$dataVar, $cipherVar, $keyVar, $optionsVar, $ivVar];
                $frame->returnVar = new \PHPCompiler\VM\Variable();

                return $frame;
            };

            $emptyVar = new \PHPCompiler\VM\Variable();
            $emptyVar->string('');
            $emptyFrame = $makeFrame($emptyVar);
            $encryptFn->execute($emptyFrame);

            $nullVar = new \PHPCompiler\VM\Variable();
            $nullVar->null();
            $nullFrame = $makeFrame($nullVar);
            $encryptFn->execute($nullFrame);

            self::assertSame(\PHPCompiler\VM\Variable::TYPE_STRING, $emptyFrame->returnVar->type);
            self::assertSame(\PHPCompiler\VM\Variable::TYPE_STRING, $nullFrame->returnVar->type);
            self::assertNotSame('', $emptyFrame->returnVar->toString());
            self::assertSame($emptyFrame->returnVar->toString(), $nullFrame->returnVar->toString());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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
