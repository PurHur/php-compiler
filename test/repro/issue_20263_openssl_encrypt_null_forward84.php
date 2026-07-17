<?php
/** Repro for #20263 — openssl_encrypt(null) TypeError under PROFILE=8.4. */
$iv = str_repeat("\0", 16);
$key = str_repeat('k', 16);
try {
    $r = openssl_encrypt(null, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
    echo 'COERCE len='.strlen((string) $r)."\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$r2 = openssl_encrypt('', 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
echo 'empty len='.strlen($r2)."\n";
