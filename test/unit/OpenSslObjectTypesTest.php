<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * OpenSSLCertificate / OpenSSLAsymmetricKey / OpenSSLCertificateSigningRequest (#7268).
 *
 * @group openssl_object_types
 */
final class OpenSslObjectTypesTest extends TestCase
{
    private const TEST_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIBdTCCAR+gAwIBAgIUQ43V2DdE7emGxnFsE7m0goBT+NwwDQYJKoZIhvcNAQEL
BQAwDzENMAsGA1UEAwwEdGVzdDAeFw0yNjA2MTMwNTEyMTVaFw0yNzA2MTMwNTEy
MTVaMA8xDTALBgNVBAMMBHRlc3QwXDANBgkqhkiG9w0BAQEFAANLADBIAkEA0v3U
b1alT3eTGKYXeaOwTCnYlFHIqbPRN9QIA5uLBoMBzYkvyYrB0Cn4JJ9z8cHXC28b
JoiMF0c4ieUKGJDbLQIDAQABo1MwUTAdBgNVHQ4EFgQUYvFstHPOXFH9MML8oieH
aAO8cDgwHwYDVR0jBBgwFoAUYvFstHPOXFH9MML8oieHaAO8cDgwDwYDVR0TAQH/
BAUwAwEB/zANBgkqhkiG9w0BAQsFAANBABzgKedHOEb9sSDCE5EPqQKzRme8+xHH
lLUgzBEC/Lp5Cj7g7xQ2xE9t8iVtgsBwSaa6WjzJWC97N8UsdFNe0i0=
-----END CERTIFICATE-----
PEM;

    public function test_openssl_object_classes_registered(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['OpenSSLCertificate', 'OpenSSLAsymmetricKey', 'OpenSSLCertificateSigningRequest'] as $class) {
            self::assertTrue(VmReflection::classExists($ctx, $class), $class);
            self::assertTrue($ctx->classes[strtolower($class)]->isInternal, $class.' internal');
            self::assertTrue($ctx->classes[strtolower($class)]->isFinal, $class.' final (#28370)');
        }

        $code = <<<'PHP'
<?php
foreach (['OpenSSLCertificate', 'OpenSSLAsymmetricKey', 'OpenSSLCertificateSigningRequest'] as $c) {
    echo $c, ':', (int) class_exists($c, false), ':', (int) is_subclass_of($c, $c, false), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'openssl_classes.php'));
        self::assertSame(
            "OpenSSLCertificate:1:0\nOpenSSLAsymmetricKey:1:0\nOpenSSLCertificateSigningRequest:1:0\n",
            ob_get_clean()
        );
    }

    /** @covers issue #28370 — php-src ext/openssl/openssl.stub.php final classes */
    public function test_openssl_object_classes_are_final(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['OpenSSLCertificate', 'OpenSSLAsymmetricKey', 'OpenSSLCertificateSigningRequest'] as $c) {
    echo $c, ':', (new ReflectionClass($c))->isFinal() ? '1' : '0', "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'openssl_classes_final.php'));
        self::assertSame(
            "OpenSSLCertificate:1\nOpenSSLAsymmetricKey:1\nOpenSSLCertificateSigningRequest:1\n",
            ob_get_clean()
        );
    }

    public function test_openssl_x509_read_returns_certificate_object(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslX509Native::available()) {
            self::markTestSkipped('libcrypto FFI unavailable for openssl_x509_read() test');
        }

        $runtime = new Runtime();
        $pem = var_export(self::TEST_CERT, true);
        $code = <<<PHP
<?php
\$pem = {$pem};
\$cert = openssl_x509_read(\$pem);
var_export(\$cert instanceof OpenSSLCertificate);
echo "\n";
var_export(is_object(\$cert));
echo "\n";
var_export(openssl_x509_read(\$cert) instanceof OpenSSLCertificate);
echo "\n";
var_export(openssl_x509_read('not-a-cert') === false);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'openssl_x509_read.php'));
        self::assertSame("true\ntrue\ntrue\ntrue\n", ob_get_clean());
    }

    public function test_openssl_x509_read_enum_operand_typeerror(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: string { case A = 'x'; }
try {
    openssl_x509_read(E::A);
} catch (Throwable $e) {
    echo $e::class, "\n", $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'openssl_x509_enum.php'));
        $out = ob_get_clean();
        self::assertStringContainsString('TypeError', $out);
        self::assertStringContainsString('openssl_x509_read(): Argument #1 ($certificate)', $out);
    }

    public function test_openssl_x509_parse_subject_issuer(): void
    {
        if (!\PHPCompiler\ext\openssl\VmOpensslX509Native::available()) {
            self::markTestSkipped('libcrypto FFI unavailable for openssl_x509_parse() test');
        }

        $runtime = new Runtime();
        $pem = var_export(self::TEST_CERT, true);
        $code = <<<PHP
<?php
\$pem = {$pem};
\$info = openssl_x509_parse(\$pem);
var_export(is_array(\$info));
echo "\n";
var_export(\$info['subject']['CN'] ?? null);
echo "\n";
var_export(\$info['issuer']['CN'] ?? null);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'openssl_x509_parse.php'));
        self::assertSame("true\n'test'\n'test'\n", ob_get_clean());
    }

    public function test_openssl_free_key_deprecated_noop(): void
    {
        $runtime = new Runtime();
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'openssl_free_key'));

        $code = <<<'PHP'
<?php
$prev = error_reporting(E_ALL);
try {
    @openssl_free_key(null);
    echo "ok\n";
} finally {
    error_reporting($prev);
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'openssl_free_key.php'));
        self::assertSame("ok\n", ob_get_clean());
    }

    public function test_openssl_pkey_free_deprecated_noop(): void
    {
        $runtime = new Runtime();
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'openssl_pkey_free'));

        $code = <<<'PHP'
<?php
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}
$prev = error_reporting(E_ALL);
try {
    $r = @openssl_pkey_free($key);
    var_export($r);
    echo "\n";
} finally {
    error_reporting($prev);
}
try {
    openssl_pkey_free(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'openssl_pkey_free.php'));
        self::assertSame("NULL\nnull-type\n", ob_get_clean());
    }
}
