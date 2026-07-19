--TEST--
AOT: openssl_encrypt()/openssl_decrypt() AES-128-CBC round-trip (#21065)
--SKIPIF--
<?php
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 4) . '/ext/openssl/VmOpensslCipherNative.php';
if (!\PHPCompiler\ext\openssl\VmOpensslCipherNative::available()) {
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

$key = '0123456789abcdef';
$iv = '0123456789abcdef';

$raw = openssl_encrypt('data', 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
echo bin2hex($raw), "\n";
echo openssl_decrypt($raw, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv), "\n";

$b64 = openssl_encrypt('data', 'AES-128-CBC', $key, 0, $iv);
echo $b64, "\n";
echo openssl_decrypt($b64, 'AES-128-CBC', $key, 0, $iv), "\n";
--EXPECT--
840a0c413dca6e7dcc58783214795053
data
hAoMQT3Kbn3MWHgyFHlQUw==
data
