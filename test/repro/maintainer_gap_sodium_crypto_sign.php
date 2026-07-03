<?php
declare(strict_types=1);
// Maintainer gap repro: sodium Ed25519 sign family (#15541).

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium not loaded\n");
    exit(0);
}

foreach ([
    'sodium_crypto_sign_keypair',
    'sodium_crypto_sign_publickey',
    'sodium_crypto_sign_secretkey',
    'sodium_crypto_sign_publickey_from_secretkey',
    'sodium_crypto_sign',
    'sodium_crypto_sign_open',
    'sodium_crypto_sign_detached',
    'sodium_crypto_sign_verify_detached',
] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "{$fn} not registered\n");
        exit(1);
    }
}

$kp = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($kp);
$pk = sodium_crypto_sign_publickey($kp);
$pk2 = sodium_crypto_sign_publickey_from_secretkey($sk);
if ($pk !== $pk2) {
    fwrite(STDERR, "publickey_from_secretkey mismatch\n");
    exit(1);
}

$msg = 'test message';
$signed = sodium_crypto_sign($msg, $sk);
$opened = sodium_crypto_sign_open($signed, $pk);
if ($opened !== $msg) {
    fwrite(STDERR, "sign/open round-trip failed\n");
    exit(1);
}

$badPk = $pk;
$badPk[0] = chr(ord($badPk[0]) ^ 0xff);
if (false !== sodium_crypto_sign_open($signed, $badPk)) {
    fwrite(STDERR, "bad signature should fail open\n");
    exit(1);
}

$sig = sodium_crypto_sign_detached($msg, $sk);
if (!sodium_crypto_sign_verify_detached($sig, $msg, $pk)) {
    fwrite(STDERR, "verify_detached failed\n");
    exit(1);
}

$badSig = $sig;
$badSig[0] = chr(ord($badSig[0]) ^ 0xff);
if (sodium_crypto_sign_verify_detached($badSig, $msg, $pk)) {
    fwrite(STDERR, "tampered signature should fail verify\n");
    exit(1);
}

echo "sign_ok\n";
