<?php

/**
 * Repro for #21445 — openssl_encrypt/openssl_decrypt(null) soft-null under PROFILE=8.4
 * (reverts wrong-direction #20263 TypeError; Zend emits E_DEPRECATED + coerces).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_openssl_crypt_null_soft_forward84.php
 */

$key = str_repeat('k', 16);
$pass = 0;
$fail = 0;

try {
    $empty = openssl_encrypt('', 'AES-128-ECB', $key);
    $null = openssl_encrypt(null, 'AES-128-ECB', $key);
    if (is_string($null) && $empty === $null && strlen($null) > 0) {
        echo "PASS: openssl_encrypt(null) coerces (len=".strlen($null).")\n";
        ++$pass;
    } else {
        echo 'FAIL: openssl_encrypt(null) -> '.var_export($null, true)."\n";
        ++$fail;
    }
} catch (Throwable $e) {
    echo 'FAIL: openssl_encrypt(null) -> '.get_class($e).': '.$e->getMessage()."\n";
    ++$fail;
}

try {
    $r = openssl_decrypt(null, 'AES-128-ECB', $key);
    if (false === $r) {
        echo "PASS: openssl_decrypt(null) -> false\n";
        ++$pass;
    } else {
        echo 'FAIL: openssl_decrypt(null) -> '.var_export($r, true)."\n";
        ++$fail;
    }
} catch (Throwable $e) {
    echo 'FAIL: openssl_decrypt(null) -> '.get_class($e).': '.$e->getMessage()."\n";
    ++$fail;
}

echo "\n$pass passed, $fail failed\n";
if ($fail > 0) {
    exit(1);
}
