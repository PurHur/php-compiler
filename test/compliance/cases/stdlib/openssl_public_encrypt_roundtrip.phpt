--TEST--
stdlib openssl_public_encrypt()/openssl_private_decrypt() RSA round-trip (#6666, ext/openssl/xp.c)
--SKIPIF--
<?php if (!PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) die('skip no libcrypto FFI'); ?>
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('openssl_public_encrypt') || !function_exists('openssl_private_decrypt')
    || !function_exists('openssl_private_encrypt') || !function_exists('openssl_public_decrypt')) {
    echo "missing\n";
    exit(1);
}

$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}

$pem = '';
if (!openssl_pkey_export($key, $pem)) {
    echo "export-fail\n";
    exit(1);
}

$data = 'hello-rsa';
$encrypted = '';
if (!openssl_public_encrypt($data, $encrypted, $pem)) {
    echo "encrypt-fail\n";
    exit(1);
}

$decrypted = '';
if (!openssl_private_decrypt($encrypted, $decrypted, $key)) {
    echo "decrypt-fail\n";
    exit(1);
}

echo $decrypted === $data ? "ok\n" : "mismatch\n";

$encrypted2 = '';
if (!openssl_private_encrypt($data, $encrypted2, $key)) {
    echo "private-encrypt-fail\n";
    exit(1);
}

$decrypted2 = '';
if (!openssl_public_decrypt($encrypted2, $decrypted2, $key)) {
    echo "public-decrypt-fail\n";
    exit(1);
}

echo $decrypted2 === $data ? "reverse-ok\n" : "reverse-mismatch\n";
?>
--EXPECT--
ok
reverse-ok
