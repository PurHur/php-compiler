--TEST--
stdlib openssl_random_pseudo_bytes() — CSPRNG + crypto_strong out-param (#4994)
--FILE--
<?php
$strong = false;
$bytes = openssl_random_pseudo_bytes(16, $strong);
echo strlen($bytes), "\n";
var_export($strong);
echo "\n";
$bytes2 = openssl_random_pseudo_bytes(8);
echo strlen($bytes2), "\n";
--EXPECT--
16
true
8
