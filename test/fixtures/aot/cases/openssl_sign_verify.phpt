--TEST--
AOT: openssl_sign()/openssl_verify() RSA-SHA256 round-trip (#3324)
--SKIPIF--
<?php
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 4) . '/ext/openssl/VmOpensslSignNative.php';
if (!\PHPCompiler\ext\openssl\VmOpensslSignNative::available()) {
    echo 'skip';
}
$evp = '/usr/include/openssl/evp.h';
if (!is_file($evp) && !is_file('/usr/local/include/openssl/evp.h')) {
    echo 'skip';
}
?>
--FILE--
<?php
declare(strict_types=1);

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

$data = 'php-compiler openssl verify probe';
$signature = '';
if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    echo "sign_fail\n";
    exit(1);
}
$result = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
echo $result, "\n";
echo openssl_verify('tampered', $signature, $publicKey, OPENSSL_ALGO_SHA256), "\n";
--EXPECT--
1
0
