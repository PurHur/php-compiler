--TEST--
openssl OPENSSL_PKCS1_PADDING / OPENSSL_NO_PADDING / OPENSSL_PKCS1_OAEP_PADDING (#24071)
--SKIPIF--
<?php if (!PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) die('skip no libcrypto FFI'); ?>
--FILE--
<?php
declare(strict_types=1);

$need = [
    'OPENSSL_PKCS1_PADDING' => 1,
    'OPENSSL_NO_PADDING' => 3,
    'OPENSSL_PKCS1_OAEP_PADDING' => 4,
    'OPENSSL_RAW_DATA' => 1,
];
// Bare names (not constant()) so AOT matches VM.
$got = [
    'OPENSSL_PKCS1_PADDING' => defined('OPENSSL_PKCS1_PADDING') ? OPENSSL_PKCS1_PADDING : null,
    'OPENSSL_NO_PADDING' => defined('OPENSSL_NO_PADDING') ? OPENSSL_NO_PADDING : null,
    'OPENSSL_PKCS1_OAEP_PADDING' => defined('OPENSSL_PKCS1_OAEP_PADDING') ? OPENSSL_PKCS1_OAEP_PADDING : null,
    'OPENSSL_RAW_DATA' => defined('OPENSSL_RAW_DATA') ? OPENSSL_RAW_DATA : null,
];
foreach ($need as $name => $want) {
    if (null === $got[$name]) {
        echo $name, "=UNDEF\n";
        continue;
    }
    echo $name, '=', $got[$name] === $want ? 'ok' : ("bad:{$got[$name]}"), "\n";
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
$encrypted = '';
if (!openssl_public_encrypt('pad-const', $encrypted, $pem, OPENSSL_PKCS1_PADDING)) {
    echo "encrypt-fail\n";
    exit(1);
}
$decrypted = '';
if (!openssl_private_decrypt($encrypted, $decrypted, $key, OPENSSL_PKCS1_PADDING)) {
    echo "decrypt-fail\n";
    exit(1);
}
echo $decrypted === 'pad-const' ? "encrypt-ok\n" : "encrypt-mismatch\n";
?>
--EXPECT--
OPENSSL_PKCS1_PADDING=ok
OPENSSL_NO_PADDING=ok
OPENSSL_PKCS1_OAEP_PADDING=ok
OPENSSL_RAW_DATA=ok
encrypt-ok
