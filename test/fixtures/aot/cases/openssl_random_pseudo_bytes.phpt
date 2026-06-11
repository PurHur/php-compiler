--TEST--
AOT openssl_random_pseudo_bytes() length and crypto_strong (#4994)
--FILE--
<?php
$strong = false;
$bytes = openssl_random_pseudo_bytes(16, $strong);
echo strlen($bytes), "\n";
echo $strong ? "true\n" : "false\n";
--EXPECT--
16
true
